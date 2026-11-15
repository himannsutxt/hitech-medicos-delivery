import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../api.dart';
import '../i18n.dart';
import '../state.dart';
import '../store.dart';
import '../ui.dart';
import 'login_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});
  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  List<Map<String, Object?>> _problems = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final p = await Store.I.problems();
    if (mounted) setState(() => _problems = p);
  }

  @override
  Widget build(BuildContext context) {
    final r = Api.I.rider ?? {};
    final support = (Api.I.settings['support_mobile'] as String?) ?? '';
    return Scaffold(
      appBar: AppBar(title: Text(tr('profile'))),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          EvaCard(
            child: Row(children: [
              CircleAvatar(radius: 28, backgroundColor: C.blue, child: Text(((r['name'] as String?) ?? '').isEmpty ? '?' : (r['name'] as String).substring(0, 1), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800))),
              const SizedBox(width: 14),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(r['name'] as String? ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                Text('${r['employee_code'] ?? ''} · ${r['mobile'] ?? ''}', style: const TextStyle(color: C.muted)),
                if ((r['vehicle_number'] as String? ?? '').isNotEmpty) Text(r['vehicle_number'] as String, style: const TextStyle(color: C.muted)),
              ])),
            ]),
          ),
          const SizedBox(height: 12),
          EvaCard(
            padding: EdgeInsets.zero,
            child: Column(children: [
              ListTile(leading: const Icon(Icons.translate), title: Text(tr('language')), onTap: () async {
                await L.set(!L.hindi);
                setState(() {});
                Day.I.touch();
              }),
              if (support.isNotEmpty) ListTile(leading: const Icon(Icons.support_agent), title: Text(tr('support')), subtitle: Text(support), onTap: () => launchUrl(Uri(scheme: 'tel', path: support))),
              ListTile(leading: const Icon(Icons.sync), title: Text(tr('sync_now')), subtitle: Text('${Api.I.pending} ${tr('waiting_sync')}'), onTap: () async {
                await Api.I.sync();
                await _load();
              }),
              ListTile(leading: const Icon(Icons.logout, color: C.red), title: Text(tr('logout'), style: const TextStyle(color: C.red)), onTap: () async {
                if (Day.I.onDuty) {
                  toast(context, tr('logout_on_duty'), error: true);
                  return;
                }
                await Api.I.logout();
                if (!context.mounted) return;
                Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
              }),
            ]),
          ),
          if (_problems.isNotEmpty) ...[
            const SizedBox(height: 16),
            Row(children: [
              Expanded(child: Text(tr('sync_problems'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
              TextButton(onPressed: () async {
                await Store.I.clearProblems();
                await _load();
              }, child: Text(L.hindi ? 'हटाएँ' : 'Clear')),
            ]),
            ..._problems.map((p) => Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Banner2(text: '${DateFormat('dd-MM HH:mm').format(DateTime.fromMillisecondsSinceEpoch(p['created'] as int))} · ${p['message']}', color: C.red, icon: Icons.warning_amber),
                )),
          ],
          const SizedBox(height: 24),
          Center(child: Text('Hitech Medicos Delivery v1.0.0\n${Uri.tryParse(Api.I.base ?? '')?.host ?? ''}', textAlign: TextAlign.center, style: const TextStyle(color: C.muted, fontSize: 12))),
        ]),
      ),
    );
  }
}
