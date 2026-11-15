import 'package:flutter/material.dart';
import '../api.dart';
import '../i18n.dart';
import '../logic.dart';
import '../ui.dart';
import 'login_screen.dart';

class SetupScreen extends StatefulWidget {
  const SetupScreen({super.key});
  @override
  State<SetupScreen> createState() => _SetupScreenState();
}

class _SetupScreenState extends State<SetupScreen> {
  final _c = TextEditingController(text: Api.I.base?.replaceAll('/api.php', '').replaceAll('https://', '') ?? '');
  bool _busy = false;
  String? _err;

  Future<void> _go() async {
    final url = normaliseServer(_c.text);
    if (url == null) {
      setState(() => _err = tr('server_bad'));
      return;
    }
    setState(() {
      _busy = true;
      _err = null;
    });
    final old = Api.I.base;
    await Api.I.setBase(url);
    final r = await Api.I.call('GET', 'me');
    setState(() => _busy = false);
    // An unauthenticated "me" returns 401 JSON: the server is reachable and is ours.
    if (r.status == 401 && r.data['error'] == 'unauthorized') {
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const LoginScreen()));
    } else {
      if (old != null) await Api.I.setBase(old);
      setState(() => _err = tr('server_unreachable'));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(24), children: [
            const SizedBox(height: 30),
            const _Logo(),
            const SizedBox(height: 28),
            Text(tr('server_title'), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(tr('server_help'), style: const TextStyle(color: C.muted, fontSize: 15)),
            const SizedBox(height: 20),
            TextField(controller: _c, keyboardType: TextInputType.url, autocorrect: false, decoration: InputDecoration(hintText: tr('server_hint'), prefixIcon: const Icon(Icons.dns_outlined), errorText: _err, errorMaxLines: 3)),
            const SizedBox(height: 20),
            BigButton(label: tr('continue'), icon: Icons.arrow_forward, busy: _busy, onPressed: _go),
            const SizedBox(height: 16),
            Center(child: TextButton(onPressed: () async {
              await L.set(!L.hindi);
              setState(() {});
            }, child: Text(tr('language')))),
          ]),
        ),
      );
}

class _Logo extends StatelessWidget {
  const _Logo();
  @override
  Widget build(BuildContext context) => Row(children: [
        Container(width: 54, height: 54, decoration: BoxDecoration(gradient: C.grad, borderRadius: BorderRadius.circular(16)), child: const Icon(Icons.local_shipping_rounded, color: Colors.white, size: 30)),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Hitech Medicos', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          Text(L.hindi ? 'डिलीवरी ऐप' : 'Delivery app', style: const TextStyle(color: C.muted)),
        ])),
      ]);
}

class AppLogo extends StatelessWidget {
  const AppLogo({super.key});
  @override
  Widget build(BuildContext context) => const _Logo();
}
