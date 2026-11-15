import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'api.dart';
import 'i18n.dart';
import 'screens/login_screen.dart';
import 'screens/setup_screen.dart';
import 'screens/shell.dart';
import 'state.dart';
import 'ui.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  await L.load();
  await Api.I.init();
  await Day.I.loadCache();
  runApp(const HmdApp());
}

class HmdApp extends StatelessWidget {
  const HmdApp({super.key});
  @override
  Widget build(BuildContext context) {
    final Widget start = Api.I.base == null ? const SetupScreen() : (Api.I.loggedIn ? const Shell() : const LoginScreen());
    return MaterialApp(
      title: 'Hitech Medicos Delivery',
      debugShowCheckedModeBanner: false,
      theme: evaTheme(),
      home: start,
    );
  }
}
