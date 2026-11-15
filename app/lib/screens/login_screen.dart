import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../api.dart';
import '../i18n.dart';
import '../state.dart';
import '../ui.dart';
import 'setup_screen.dart';
import 'shell.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _m = TextEditingController();
  final _p = TextEditingController();
  bool _busy = false;
  String? _err;

  Future<void> _login() async {
    final digits = _m.text.replaceAll(RegExp(r'\D'), '');
    if (digits.length < 10 || _p.text.length != 4) {
      setState(() => _err = L.hindi ? 'मोबाइल नंबर और 4 अंकों का पिन डालें।' : 'Enter your mobile number and 4-digit PIN.');
      return;
    }
    setState(() {
      _busy = true;
      _err = null;
    });
    final r = await Api.I.login(digits, _p.text);
    if (!mounted) return;
    setState(() => _busy = false);
    if (r.status == 200 && Api.I.loggedIn) {
      await Day.I.refresh();
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(MaterialPageRoute(builder: (_) => const Shell()), (_) => false);
    } else {
      setState(() => _err = r.message);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: ListView(padding: const EdgeInsets.all(24), children: [
            const SizedBox(height: 30),
            const AppLogo(),
            const SizedBox(height: 28),
            Text(tr('login'), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            const SizedBox(height: 18),
            TextField(controller: _m, keyboardType: TextInputType.phone, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')), LengthLimitingTextInputFormatter(14)], decoration: InputDecoration(labelText: tr('mobile'), prefixIcon: const Icon(Icons.phone_android))),
            const SizedBox(height: 14),
            TextField(controller: _p, obscureText: true, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(4)], decoration: InputDecoration(labelText: tr('pin'), prefixIcon: const Icon(Icons.lock_outline)), onSubmitted: (_) => _login()),
            if (_err != null) Padding(padding: const EdgeInsets.only(top: 14), child: Banner2(text: _err!, color: C.red, icon: Icons.error_outline)),
            const SizedBox(height: 20),
            BigButton(label: tr('login'), icon: Icons.login, busy: _busy, onPressed: _login),
            const SizedBox(height: 12),
            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              TextButton(onPressed: () => Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const SetupScreen())), child: Text(tr('change_server'))),
              TextButton(onPressed: () async {
                await L.set(!L.hindi);
                setState(() {});
              }, child: Text(tr('language'))),
            ]),
            const SizedBox(height: 8),
            Text(Uri.tryParse(Api.I.base ?? '')?.host ?? '', textAlign: TextAlign.center, style: const TextStyle(color: C.muted, fontSize: 12)),
          ]),
        ),
      );
}
