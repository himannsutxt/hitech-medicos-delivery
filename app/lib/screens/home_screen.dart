import 'dart:async';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../api.dart';
import '../i18n.dart';
import '../logic.dart';
import '../state.dart';
import '../tracker.dart';
import '../ui.dart';
import 'deliveries_screen.dart';
import 'order_screen.dart';
import 'perms.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.onTab});
  final void Function(int) onTab;
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  bool _busy = false;
  PermState? _perm;

  @override
  void initState() {
    super.initState();
    _check();
  }

  Future<void> _check() async {
    final p = await checkPerms();
    if (mounted) setState(() => _perm = p);
  }

  Future<void> _startDuty() async {
    final perm = await askPerms(context);
    setState(() => _perm = perm);
    if (!perm.fine || !perm.gpsOn) {
      if (mounted) toast(context, tr('gps_off'), error: true);
      return;
    }
    String? selfie;
    if (mounted) {
      final take = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text(tr('selfie')),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('skip'))), FilledButton.icon(onPressed: () => Navigator.pop(c, true), icon: const Icon(Icons.camera_front), label: Text(tr('take_photo')))],
        ),
      );
      if (take == true) {
        final x = await ImagePicker().pickImage(source: ImageSource.camera, preferredCameraDevice: CameraDevice.front, maxWidth: 900, imageQuality: 65);
        selfie = x?.path;
      }
    }
    setState(() => _busy = true);
    final pos = await Tracker.I.fix();
    final r = await Api.I.action('duty/start', {'lat': pos?.latitude, 'lng': pos?.longitude, 'accuracy': pos?.accuracy}, photoPath: selfie, photoKind: 'selfie');
    setState(() => _busy = false);
    if (!mounted) return;
    if (r.ok) {
      Day.I.setDuty(r.data['duty'] is Map ? Map<String, dynamic>.from(r.data['duty'] as Map) : {'status': 'active'});
      await Tracker.I.start();
      toast(context, r.queued ? r.message : (r.data['in_geofence'] == false ? (L.hindi ? 'ड्यूटी शुरू। आप स्टोर के बाहर हैं।' : 'Duty started. Note: you are outside the store area.') : (L.hindi ? 'ड्यूटी शुरू' : 'Duty started')));
      unawaited(Day.I.refresh());
    } else {
      toast(context, r.message, error: true);
    }
  }

  Future<void> _routeStart() async {
    setState(() => _busy = true);
    final pos = await Tracker.I.fix();
    final r = await Api.I.action('route/start', {'lat': pos?.latitude, 'lng': pos?.longitude}, queueable: false);
    setState(() => _busy = false);
    if (!mounted) return;
    if (r.ok) {
      for (final d in Day.I.deliveries.where((d) => d.status == 'loaded')) {
        Day.I.setStatus(d.id, 'out');
      }
      toast(context, L.hindi ? 'रूट शुरू। सुरक्षित चलाएँ!' : 'Route started. Ride safe!');
      unawaited(Day.I.refresh());
    } else {
      toast(context, r.message, error: true);
    }
  }

  Future<void> _endDuty() async {
    final ok = await showDialog<bool>(context: context, builder: (c) => AlertDialog(title: Text(tr('end_duty')), content: Text(tr('sure_end')), actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('cancel'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('confirm')))]));
    if (ok != true) return;
    setState(() => _busy = true);
    await Tracker.I.flush();
    await Api.I.sync();
    final pos = await Tracker.I.fix();
    final r = await Api.I.action('duty/end', {'lat': pos?.latitude, 'lng': pos?.longitude}, queueable: false);
    setState(() => _busy = false);
    if (!mounted) return;
    if (r.ok) {
      await Tracker.I.stop();
      Day.I.setDuty(null);
      final km = (r.data['km'] is Map) ? (r.data['km']['km'] as num?) ?? 0 : 0;
      toast(context, L.hindi ? 'ड्यूटी खत्म। आज $km KM।' : 'Duty ended. You travelled $km km today.');
      unawaited(Day.I.refresh());
    } else {
      toast(context, r.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: Listenable.merge([Day.I, Api.I]),
        builder: (context, _) {
          final d = Day.I;
          final s = d.summary;
          final rider = Api.I.rider ?? {};
          return Scaffold(
            body: RefreshIndicator(
              onRefresh: () async {
                await Day.I.refresh();
                await _check();
              },
              child: ListView(padding: EdgeInsets.zero, children: [
                _Header(name: rider['name'] as String? ?? '', onDuty: d.onDuty, company: rider['company'] as String? ?? 'Hitech Medicos'),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (!Api.I.online || Api.I.pending > 0)
                      Banner2(text: '${Api.I.online ? '' : '${tr('offline')} · '}${Api.I.pending} ${tr('waiting_sync')}', color: C.amber, icon: Icons.cloud_off, action: tr('sync_now'), onAction: () => Api.I.sync()),
                    if (_perm != null && !_perm!.gpsOn) Banner2(text: tr('gps_off'), color: C.red, icon: Icons.location_off, action: tr('fix'), onAction: () async {
                      final p = await askPerms(context);
                      setState(() => _perm = p);
                    }),
                    if (_perm != null && _perm!.gpsOn && !_perm!.always) Banner2(text: tr('perm_bg'), color: C.red, icon: Icons.my_location, action: tr('fix'), onAction: () async {
                      final p = await askPerms(context);
                      setState(() => _perm = p);
                    }),
                    if (_perm != null && !_perm!.battery && d.onDuty) Banner2(text: tr('battery_tip'), color: C.amber, icon: Icons.battery_alert, action: tr('fix'), onAction: () async {
                      final p = await askPerms(context);
                      setState(() => _perm = p);
                    }),
                    if (d.error != null && d.deliveries.isEmpty) Banner2(text: d.error!, color: C.red, icon: Icons.error_outline),
                    GridView.count(
                      crossAxisCount: 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      mainAxisSpacing: 10,
                      crossAxisSpacing: 10,
                      childAspectRatio: 2.35,
                      children: [
                        Kpi(value: '${d.deliveries.length}', label: tr('assigned'), icon: Icons.inventory_2_outlined, color: C.blue),
                        Kpi(value: '${d.count((x) => x.status == 'delivered')}', label: tr('delivered'), icon: Icons.check_circle_outline, color: C.green),
                        Kpi(value: '${d.count((x) => x.open || x.status == 'loaded' || x.status == 'assigned')}', label: tr('pending'), icon: Icons.schedule, color: C.purple),
                        Kpi(value: '${d.count((x) => const ['rescheduled', 'rto', 'not_delivered'].contains(x.status))}', label: tr('failed'), icon: Icons.cancel_outlined, color: C.red),
                        Kpi(value: '${((s['km'] as num?) ?? 0).toStringAsFixed(1)} km', label: tr('km_today'), icon: Icons.route, color: C.amber),
                        Kpi(value: inr(d.cash, decimals: false), label: tr('cash_in_hand'), icon: Icons.account_balance_wallet_outlined, color: d.codLimit > 0 && d.cash > d.codLimit ? C.red : C.teal),
                      ],
                    ),
                    const SizedBox(height: 16),
                    ..._actions(d),
                  ]),
                ),
              ]),
            ),
          );
        },
      );

  List<Widget> _actions(Day d) {
    if (!d.onDuty) {
      return [
        BigButton(label: tr('start_duty'), icon: Icons.play_circle_fill, busy: _busy, color: C.green, onPressed: _startDuty),
        const SizedBox(height: 10),
        Text(d.deliveries.isEmpty ? tr('no_orders') : (L.hindi ? '${d.deliveries.length} ऑर्डर आपके लिए असाइन हैं।' : '${d.deliveries.length} orders are assigned to you.'), textAlign: TextAlign.center, style: const TextStyle(color: C.muted)),
      ];
    }
    final w = <Widget>[];
    final cur = d.current ?? d.next;
    if (d.toScan > 0) {
      w.add(BigButton(label: '${tr('scan_parcels')} (${d.toScan} ${tr('left_to_scan')})', icon: Icons.qr_code_scanner, onPressed: () => widget.onTab(2)));
      w.add(const SizedBox(height: 10));
    }
    if (d.loaded > 0) {
      w.add(BigButton(label: '${tr('start_route')} (${d.loaded})', icon: Icons.two_wheeler, busy: _busy, onPressed: _routeStart));
      w.add(const SizedBox(height: 10));
    }
    if (cur != null) {
      w.add(Text(d.current != null ? tr('current_order') : tr('next_order'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)));
      w.add(const SizedBox(height: 8));
      w.add(OrderTile(d: cur, onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: cur.id)))));
      w.add(const SizedBox(height: 10));
    } else if (d.toScan == 0 && d.loaded == 0) {
      w.add(Banner2(text: tr('all_done'), color: C.green, icon: Icons.flag));
      if (d.cash > 0 && !d.pendingSettlement) {
        w.add(BigButton(label: '${tr('deposit_cod')} · ${inr(d.cash, decimals: false)}', icon: Icons.payments, color: C.teal, onPressed: () => widget.onTab(3)));
        w.add(const SizedBox(height: 10));
      }
    }
    w.add(BigButton(label: tr('end_duty'), icon: Icons.stop_circle_outlined, busy: _busy, outline: true, color: C.red, onPressed: _endDuty));
    return w;
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.name, required this.onDuty, required this.company});
  final String name, company;
  final bool onDuty;
  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.fromLTRB(20, MediaQuery.of(context).padding.top + 16, 20, 22),
        decoration: const BoxDecoration(gradient: C.grad, borderRadius: BorderRadius.vertical(bottom: Radius.circular(24))),
        child: Row(children: [
          CircleAvatar(radius: 24, backgroundColor: Colors.white24, child: Text(name.isNotEmpty ? name[0] : '?', style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(name, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
            Text(company, style: const TextStyle(color: Colors.white70)),
          ])),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(color: onDuty ? C.green : Colors.white24, borderRadius: BorderRadius.circular(99)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(onDuty ? Icons.circle : Icons.circle_outlined, color: Colors.white, size: 10),
              const SizedBox(width: 6),
              Text(onDuty ? tr('on_duty') : tr('off_duty'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
            ]),
          ),
        ]),
      );
}
