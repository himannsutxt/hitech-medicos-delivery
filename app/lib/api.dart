// HTTP client for the rider API with token refresh, offline outbox and sync.
import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';
import 'i18n.dart';
import 'logic.dart';
import 'store.dart';

class ApiResult {
  ApiResult(this.status, this.data, {this.queued = false});
  final int status;
  final Map<String, dynamic> data;
  final bool queued;
  bool get ok => queued || (status >= 200 && status < 300 && data['ok'] != false);
  bool get offline => status == 0;
  String get message {
    if (queued) return tr('saved_offline');
    final m = L.hindi ? (data['message_hi'] ?? data['message']) : data['message'];
    if (m is String && m.isNotEmpty) return m;
    return status == 0 ? tr('server_unreachable') : 'Error $status';
  }
}

class Api extends ChangeNotifier {
  Api._();
  static final Api I = Api._();
  late SharedPreferences _p;
  String? base;
  String? _access;
  String? _refresh;
  late String deviceId;
  Map<String, dynamic>? rider;
  Map<String, dynamic> settings = {};
  int pending = 0;
  bool online = true;
  Future<bool>? _refreshing;
  bool _syncing = false;

  Future<void> init() async {
    _p = await SharedPreferences.getInstance();
    base = _p.getString('base');
    _access = _p.getString('access');
    _refresh = _p.getString('refresh');
    deviceId = _p.getString('device_id') ?? const Uuid().v4();
    await _p.setString('device_id', deviceId);
    final r = _p.getString('rider');
    rider = r != null ? jsonDecode(r) as Map<String, dynamic> : null;
    final s = _p.getString('settings');
    settings = s != null ? jsonDecode(s) as Map<String, dynamic> : {};
    pending = await Store.I.pendingCount();
  }

  bool get loggedIn => _refresh != null && rider != null;

  Future<void> setBase(String b) async {
    base = b;
    await _p.setString('base', b);
  }

  Uri _u(String path) => Uri.parse('$base?p=${Uri.encodeQueryComponent(path)}');

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Device-Id': deviceId,
        if (_access != null) 'Authorization': 'Bearer $_access',
      };

  Future<ApiResult> _raw(String method, String path, Map<String, dynamic>? body) async {
    try {
      final payload = jsonEncode({...?body, 'device_id': deviceId});
      final r = method == 'GET'
          ? await http.get(_u(path), headers: _headers).timeout(const Duration(seconds: 20))
          : await http.post(_u(path), headers: _headers, body: payload).timeout(const Duration(seconds: 25));
      Map<String, dynamic> data;
      try {
        data = jsonDecode(utf8.decode(r.bodyBytes)) as Map<String, dynamic>;
      } catch (_) {
        data = {'ok': false, 'message': 'Server returned an unexpected reply (${r.statusCode}).'};
      }
      _setOnline(true);
      return ApiResult(r.statusCode, data);
    } on SocketException {
      _setOnline(false);
      return ApiResult(0, {'ok': false});
    } on TimeoutException {
      _setOnline(false);
      return ApiResult(0, {'ok': false});
    } on HandshakeException {
      return ApiResult(0, {'ok': false, 'message': 'Secure connection failed (SSL).'});
    } on http.ClientException {
      _setOnline(false);
      return ApiResult(0, {'ok': false});
    }
  }

  void _setOnline(bool v) {
    if (online != v) {
      online = v;
      notifyListeners();
    }
  }

  Future<ApiResult> call(String method, String path, [Map<String, dynamic>? body]) async {
    var r = await _raw(method, path, body);
    if (r.status == 401 && _refresh != null && path != 'auth/login') {
      if (await _doRefresh()) {
        r = await _raw(method, path, body);
      } else {
        await _clearSession();
      }
    }
    return r;
  }

  Future<bool> _doRefresh() {
    return _refreshing ??= () async {
      final r = await _raw('POST', 'auth/refresh', {'refresh_token': _refresh});
      _refreshing = null;
      if (r.status == 200 && r.data['access_token'] != null) {
        await _saveTokens(r.data);
        return true;
      }
      return false;
    }();
  }

  Future<void> _saveTokens(Map<String, dynamic> d) async {
    _access = d['access_token'] as String?;
    _refresh = d['refresh_token'] as String? ?? _refresh;
    await _p.setString('access', _access ?? '');
    if (_refresh != null) await _p.setString('refresh', _refresh!);
  }

  Future<void> _clearSession() async {
    _access = null;
    _refresh = null;
    rider = null;
    await _p.remove('access');
    await _p.remove('refresh');
    await _p.remove('rider');
    notifyListeners();
  }

  Future<ApiResult> login(String mobile, String pin) async {
    final r = await _raw('POST', 'auth/login', {'mobile': mobile, 'pin': pin});
    if (r.status == 200 && r.data['access_token'] != null) {
      await _saveTokens(r.data);
      rider = r.data['rider'] as Map<String, dynamic>;
      await _p.setString('rider', jsonEncode(rider));
      await loadMe();
    }
    return r;
  }

  Future<void> loadMe() async {
    final r = await call('GET', 'me');
    if (r.ok && r.data['settings'] is Map) {
      settings = Map<String, dynamic>.from(r.data['settings'] as Map);
      await _p.setString('settings', jsonEncode(settings));
    }
  }

  Future<void> logout() async {
    await call('POST', 'auth/logout', {});
    await _clearSession();
  }

  /// Uploads a photo; returns media id or null.
  Future<int?> upload(String kind, String filePath, {double? lat, double? lng}) async {
    Future<http.StreamedResponse> send() async {
      final req = http.MultipartRequest('POST', _u('media'))
        ..headers.addAll({'X-Device-Id': deviceId, if (_access != null) 'Authorization': 'Bearer $_access'})
        ..fields['kind'] = kind
        ..fields['device_id'] = deviceId;
      if (lat != null && lng != null) {
        req.fields['lat'] = '$lat';
        req.fields['lng'] = '$lng';
      }
      req.files.add(await http.MultipartFile.fromPath('file', filePath));
      return req.send().timeout(const Duration(seconds: 60));
    }

    try {
      var s = await send();
      if (s.statusCode == 401 && await _doRefresh()) s = await send();
      final body = jsonDecode(await s.stream.bytesToString()) as Map<String, dynamic>;
      _setOnline(true);
      return body['ok'] == true ? (body['media_id'] as num).toInt() : null;
    } catch (_) {
      _setOnline(false);
      return null;
    }
  }

  /// Sends an action now if possible, otherwise saves it to the outbox (in order).
  /// Server rejections (4xx) are returned so the rider sees the reason.
  Future<ApiResult> action(String path, Map<String, dynamic> body, {String? photoPath, String? photoKind, bool queueable = true}) async {
    final b = {...body, 'client_uuid': const Uuid().v4(), 'device_at': DateTime.now().toUtc().toIso8601String()};
    if (pending > 0 && queueable) {
      await Store.I.enqueue(path, b, photoPath: photoPath, photoKind: photoKind);
      await _refreshPending();
      unawaited(sync());
      return ApiResult(202, {'ok': true}, queued: true);
    }
    if (photoPath != null) {
      final id = await upload(photoKind ?? 'pod', photoPath, lat: (b['lat'] as num?)?.toDouble(), lng: (b['lng'] as num?)?.toDouble());
      if (id == null) {
        if (!queueable) return ApiResult(0, {'ok': false});
        await Store.I.enqueue(path, b, photoPath: photoPath, photoKind: photoKind);
        await _refreshPending();
        return ApiResult(202, {'ok': true}, queued: true);
      }
      b['photo_media_id'] = id;
      if (photoKind == 'selfie') b['selfie_media_id'] = id;
    }
    final r = await call('POST', path, b);
    if (isRetryable(r.status) && queueable) {
      await Store.I.enqueue(path, b);
      await _refreshPending();
      return ApiResult(202, {'ok': true}, queued: true);
    }
    return r;
  }

  Future<void> _refreshPending() async {
    pending = await Store.I.pendingCount();
    notifyListeners();
  }

  /// Sends queued actions oldest first. Stops at the first network failure.
  Future<void> sync() async {
    if (_syncing || !loggedIn) return;
    _syncing = true;
    try {
      for (final row in await Store.I.outbox()) {
        final id = row['id'] as int;
        final body = Map<String, dynamic>.from(jsonDecode(row['body'] as String) as Map);
        final photo = row['photo_path'] as String?;
        if (photo != null) {
          if (!File(photo).existsSync()) {
            await Store.I.updateBody(id, body);
          } else {
            final mid = await upload(row['photo_kind'] as String? ?? 'pod', photo, lat: (body['lat'] as num?)?.toDouble(), lng: (body['lng'] as num?)?.toDouble());
            if (mid == null) break;
            body['photo_media_id'] = mid;
            if (row['photo_kind'] == 'selfie') body['selfie_media_id'] = mid;
            await Store.I.updateBody(id, body);
          }
        }
        final r = await call('POST', row['path'] as String, body);
        if (isRetryable(r.status)) {
          await Store.I.bumpTries(id);
          break;
        }
        if (!r.ok) {
          await Store.I.addProblem('${row['path']}: ${r.message}');
        }
        await Store.I.remove(id);
      }
    } finally {
      _syncing = false;
      await _refreshPending();
    }
  }
}
