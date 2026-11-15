import 'dart:async';
import 'package:flutter/material.dart';
import '../api.dart';
import '../i18n.dart';
import '../state.dart';
import '../tracker.dart';
import '../ui.dart';
import 'cod_screen.dart';
import 'deliveries_screen.dart';
import 'home_screen.dart';
import 'login_screen.dart';
import 'profile_screen.dart';
import 'scan_screen.dart';

class Shell extends StatefulWidget {
  const Shell({super.key});
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> with WidgetsBindingObserver {
  int _i = 0;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    Api.I.addListener(_onApi);
    Tracker.I.onArrived = _autoArrived;
    unawaited(Day.I.refresh());
    _poll = Timer.periodic(const Duration(seconds: 60), (_) => Day.I.refresh());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    Api.I.removeListener(_onApi);
    _poll?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    if (s == AppLifecycleState.resumed) unawaited(Day.I.refresh());
  }

  void _onApi() {
    if (!Api.I.loggedIn && mounted) {
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
    }
  }

  /// Called by the tracker when the rider enters the customer geofence.
  Future<void> _autoArrived(int id) async {
    final p = Tracker.I.last;
    Day.I.setStatus(id, 'arrived');
    await Api.I.action('deliveries/$id/event', {'type': 'arrived', 'lat': p?.latitude, 'lng': p?.longitude, 'manual': false});
    if (mounted) toast(context, L.hindi ? 'ग्राहक के पास पहुँचे' : 'Arrived at customer');
  }

  @override
  Widget build(BuildContext context) {
    final pages = [HomeScreen(onTab: (i) => setState(() => _i = i)), const DeliveriesScreen(), const ScanScreen(), const CodScreen(), const ProfileScreen()];
    return Scaffold(
      body: IndexedStack(index: _i, children: pages),
      bottomNavigationBar: ListenableBuilder(
        listenable: Day.I,
        builder: (_, __) => NavigationBar(
          selectedIndex: _i,
          onDestinationSelected: (i) => setState(() => _i = i),
          destinations: [
            NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home), label: tr('home')),
            NavigationDestination(
                icon: Badge(isLabelVisible: Day.I.count((d) => d.status == 'out' || d.open) > 0, label: Text('${Day.I.count((d) => d.status == 'out' || d.open)}'), child: const Icon(Icons.list_alt_outlined)),
                selectedIcon: const Icon(Icons.list_alt),
                label: tr('deliveries')),
            NavigationDestination(icon: const Icon(Icons.qr_code_scanner), label: tr('scan')),
            NavigationDestination(icon: const Icon(Icons.currency_rupee), label: tr('cod')),
            NavigationDestination(icon: const Icon(Icons.person_outline), selectedIcon: const Icon(Icons.person), label: tr('profile')),
          ],
        ),
      ),
      backgroundColor: C.bg,
    );
  }
}
