// Today's work for the rider: deliveries, duty, cash. Cached for offline use.
import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'tracker.dart';

class Delivery {
  Delivery(this.m);
  final Map<String, dynamic> m;
  int get id => (m['delivery_id'] as num).toInt();
  String get orderNo => m['order_no'] as String;
  String get name => m['customer_name'] as String? ?? '';
  String get mobile => m['customer_mobile'] as String? ?? '';
  String get address => m['address'] as String? ?? '';
  String get landmark => m['landmark'] as String? ?? '';
  String get status => m['status'] as String;
  set status(String s) => m['status'] = s;
  bool get cod => m['payment_type'] == 'cod';
  double get codAmount => (m['cod_amount'] as num?)?.toDouble() ?? 0;
  double? get lat => (m['cust_lat'] as num?)?.toDouble();
  double? get lng => (m['cust_lng'] as num?)?.toDouble();
  String get priority => m['priority'] as String? ?? 'normal';
  bool get cold => (m['is_cold_chain'] as num? ?? 0) == 1;
  bool get otpRequired => m['otp_required'] == true;
  int get items => (m['items_count'] as num?)?.toInt() ?? 1;
  int get attempts => (m['attempt_count'] as num?)?.toInt() ?? 0;
  String get notes => m['notes'] as String? ?? '';
  bool get open => const ['out', 'en_route', 'arrived'].contains(status);
  bool get closed => const ['delivered', 'rescheduled', 'rto', 'returned', 'cancelled', 'not_delivered'].contains(status);
}

class Day extends ChangeNotifier {
  Day._();
  static final Day I = Day._();
  List<Delivery> deliveries = [];
  Map<String, dynamic>? duty;
  Map<String, dynamic> summary = {};
  double cash = 0;
  double codLimit = 0;
  bool pendingSettlement = false;
  bool loading = false;
  String? error;
  DateTime? updated;

  bool get onDuty => duty != null;
  int get toScan => deliveries.where((d) => d.status == 'assigned').length;
  int get loaded => deliveries.where((d) => d.status == 'loaded').length;
  int count(bool Function(Delivery) f) => deliveries.where(f).length;
  Delivery? get current => deliveries.where((d) => d.status == 'en_route' || d.status == 'arrived').firstOrNull;
  Delivery? get next => deliveries.where((d) => d.status == 'out').firstOrNull;

  Future<void> loadCache() async {
    final p = await SharedPreferences.getInstance();
    final c = p.getString('day');
    if (c != null) _apply(jsonDecode(c) as Map<String, dynamic>, save: false);
  }

  Future<void> refresh() async {
    loading = true;
    notifyListeners();
    await Api.I.sync();
    final r = await Api.I.call('GET', 'deliveries/today');
    loading = false;
    if (r.ok) {
      error = null;
      // Keep local optimistic statuses while actions are still waiting to sync.
      if (Api.I.pending == 0) _apply(r.data);
    } else {
      error = r.message;
    }
    notifyListeners();
  }

  void _apply(Map<String, dynamic> d, {bool save = true}) {
    deliveries = ((d['deliveries'] as List?) ?? []).map((e) => Delivery(Map<String, dynamic>.from(e as Map))).toList();
    duty = d['duty'] is Map ? Map<String, dynamic>.from(d['duty'] as Map) : null;
    summary = d['summary'] is Map ? Map<String, dynamic>.from(d['summary'] as Map) : {};
    cash = (d['cash_in_hand'] as num?)?.toDouble() ?? 0;
    codLimit = (d['cod_limit'] as num?)?.toDouble() ?? 0;
    pendingSettlement = d['pending_settlement'] == true;
    updated = DateTime.now();
    if (save) {
      SharedPreferences.getInstance().then((p) => p.setString('day', jsonEncode(d)));
    }
    _syncTracker();
  }

  void _syncTracker() {
    if (onDuty && !Tracker.I.running) unawaited(Tracker.I.start());
    if (!onDuty && Tracker.I.running) unawaited(Tracker.I.stop());
    final c = deliveries.where((x) => x.status == 'en_route').firstOrNull;
    Tracker.I.setTarget(c?.id, c?.lat, c?.lng);
    if (c == null) Tracker.I.currentDeliveryId = current?.id;
  }

  /// Local update after an action (also when it is queued offline).
  void setStatus(int id, String status) {
    for (final d in deliveries) {
      if (d.id == id) d.status = status;
    }
    _syncTracker();
    notifyListeners();
  }

  void setDuty(Map<String, dynamic>? d) {
    duty = d;
    _syncTracker();
    notifyListeners();
  }

  void touch() => notifyListeners();

  void addCash(double v) {
    cash += v;
    notifyListeners();
  }
}
