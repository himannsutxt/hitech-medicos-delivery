// Background GPS tracking while on duty (Android foreground service via geolocator).
import 'dart:async';
import 'dart:convert';
import 'package:battery_plus/battery_plus.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'logic.dart';
import 'store.dart';

class Tracker {
  Tracker._();
  static final Tracker I = Tracker._();

  StreamSubscription<Position>? _sub;
  Timer? _flush;
  Timer? _beat;
  Position? last;
  Position? _lastStored;
  DateTime? _lastStoredAt;
  int? currentDeliveryId;
  double? _targetLat, _targetLng;
  ArrivalDetector? _arrival;
  void Function(int deliveryId)? onArrived;
  final _battery = Battery();
  int _batteryLevel = -1;
  bool get running => _sub != null;

  /// Order the rider is heading to; arrival is detected automatically.
  void setTarget(int? deliveryId, double? lat, double? lng) {
    currentDeliveryId = deliveryId;
    _targetLat = lat;
    _targetLng = lng;
    _arrival = (lat != null && lng != null) ? ArrivalDetector(radiusM: ((Api.I.settings['customer_geofence_m'] as num?) ?? 150).toDouble()) : null;
  }

  Future<void> start() async {
    if (_sub != null) return;
    final s = Api.I.settings;
    final settings = AndroidSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      intervalDuration: Duration(seconds: ((s['gps_interval_moving'] as num?) ?? 15).toInt()),
      foregroundNotificationConfig: const ForegroundNotificationConfig(
        notificationTitle: 'Hitech Medicos Delivery',
        notificationText: 'On duty — location is being shared with the store',
        enableWakeLock: true,
      ),
    );
    _sub = Geolocator.getPositionStream(locationSettings: settings).listen(_onPosition, onError: (_) {});
    _flush = Timer.periodic(const Duration(seconds: 60), (_) => flush());
    _beat = Timer.periodic(const Duration(minutes: 2), (_) => heartbeat());
    unawaited(heartbeat());
  }

  Future<void> stop() async {
    await _sub?.cancel();
    _sub = null;
    _flush?.cancel();
    _beat?.cancel();
    await flush();
  }

  Future<void> _onPosition(Position p) async {
    last = p;
    final s = Api.I.settings;
    final now = DateTime.now();
    final need = intervalFor(p.speed < 0 ? 0 : p.speed,
        moving: ((s['gps_interval_moving'] as num?) ?? 15).toInt(),
        slow: ((s['gps_interval_slow'] as num?) ?? 30).toInt(),
        stationary: ((s['gps_interval_stationary'] as num?) ?? 180).toInt());
    final moved = _lastStored == null ? 9999.0 : distanceM(_lastStored!.latitude, _lastStored!.longitude, p.latitude, p.longitude);
    final turned = _lastStored != null && (p.heading - _lastStored!.heading).abs() > 30 && p.speed > 3;
    if (_lastStoredAt == null || now.difference(_lastStoredAt!).inSeconds >= need || moved > 100 || turned) {
      await _store(p);
    }
    if (_arrival != null && currentDeliveryId != null && _targetLat != null) {
      final hit = _arrival!.feed(lat: p.latitude, lng: p.longitude, custLat: _targetLat!, custLng: _targetLng!, speedMs: p.speed < 0 ? 0 : p.speed, accuracy: p.accuracy);
      if (hit) {
        final id = currentDeliveryId!;
        setTarget(null, null, null);
        onArrived?.call(id);
      }
    }
  }

  Future<void> _store(Position p) async {
    _lastStored = p;
    _lastStoredAt = DateTime.now();
    final prefs = await SharedPreferences.getInstance();
    final seq = (prefs.getInt('gps_seq') ?? 0) + 1;
    await prefs.setInt('gps_seq', seq);
    if (_batteryLevel < 0 || seq % 10 == 0) {
      try {
        _batteryLevel = await _battery.batteryLevel;
      } catch (_) {}
    }
    await Store.I.addPoint({
      'seq': seq,
      'lat': p.latitude,
      'lng': p.longitude,
      'accuracy': p.accuracy,
      'speed': p.speed < 0 ? 0 : p.speed,
      'heading': p.heading,
      'battery': _batteryLevel >= 0 ? _batteryLevel : null,
      'is_mock': p.isMocked,
      'delivery_id': currentDeliveryId,
      'device_at': p.timestamp.toUtc().toIso8601String(),
    });
  }

  /// Records a point right now (used for key events like delivered or duty start).
  Future<Position?> fix() async {
    try {
      final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)));
      last = p;
      if (running) await _store(p);
      return p;
    } catch (_) {
      return last;
    }
  }

  Future<void> flush() async {
    // Queued actions (e.g. an offline duty start) must reach the server before GPS points.
    if (Api.I.pending > 0) {
      await Api.I.sync();
      if (Api.I.pending > 0) return;
    }
    while (true) {
      final rows = await Store.I.points(limit: 200);
      if (rows.isEmpty) return;
      final pts = rows.map((r) => r['point']).toList();
      final r = await Api.I.call('POST', 'locations/batch', {'points': pts.map((e) => _decode(e as String)).toList()});
      if (!r.ok) return;
      await Store.I.removePoints(rows.last['id'] as int);
      if (rows.length < 200) return;
    }
  }

  Map<String, dynamic> _decode(String s) => Map<String, dynamic>.from(jsonDecode(s) as Map);

  Future<void> heartbeat() async {
    bool gpsOn = true;
    try {
      gpsOn = await Geolocator.isLocationServiceEnabled();
      _batteryLevel = await _battery.batteryLevel;
    } catch (_) {}
    final perm = await Geolocator.checkPermission();
    await Api.I.call('POST', 'heartbeat', {
      'gps_on': gpsOn,
      'permission': perm.name,
      'background': perm == LocationPermission.always,
      'battery': _batteryLevel,
      'app_version': '1.0.0',
    });
    unawaited(Api.I.sync());
  }
}
