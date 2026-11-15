// Eva-style look for the rider app: big buttons, soft cards, clear status colours.
import 'package:flutter/material.dart';

class C {
  static const ink = Color(0xFF14254A);
  static const muted = Color(0xFF6F7D95);
  static const line = Color(0xFFE4E9F2);
  static const bg = Color(0xFFF4F7FC);
  static const blue = Color(0xFF2F6FED);
  static const violet = Color(0xFF6B5CF0);
  static const green = Color(0xFF1FB57A);
  static const amber = Color(0xFFF0A23A);
  static const red = Color(0xFFEC5B6F);
  static const purple = Color(0xFF8B63F0);
  static const teal = Color(0xFF20B2C4);
  static const grad = LinearGradient(colors: [violet, blue], begin: Alignment.topLeft, end: Alignment.bottomRight);
}

ThemeData evaTheme() {
  final base = ThemeData(useMaterial3: true, colorScheme: ColorScheme.fromSeed(seedColor: C.blue, primary: C.blue, surface: Colors.white), scaffoldBackgroundColor: C.bg);
  return base.copyWith(
    appBarTheme: const AppBarTheme(backgroundColor: Colors.white, foregroundColor: C.ink, elevation: 0, scrolledUnderElevation: 0.5, titleTextStyle: TextStyle(color: C.ink, fontSize: 18, fontWeight: FontWeight.w800)),
    textTheme: base.textTheme.apply(bodyColor: C.ink, displayColor: C.ink),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: C.line)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: C.line)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: C.blue, width: 1.6)),
    ),
    navigationBarTheme: NavigationBarThemeData(backgroundColor: Colors.white, indicatorColor: C.blue.withValues(alpha: 0.12), labelTextStyle: WidgetStateProperty.all(const TextStyle(fontSize: 12, fontWeight: FontWeight.w600))),
  );
}

class EvaCard extends StatelessWidget {
  const EvaCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.onTap, this.color});
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? color;
  @override
  Widget build(BuildContext context) => Material(
        color: color ?? Colors.white,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          child: Container(
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), border: Border.all(color: C.line)),
            padding: padding,
            child: child,
          ),
        ),
      );
}

/// Large primary action, at least 56 dp tall, usable with gloves.
class BigButton extends StatelessWidget {
  const BigButton({super.key, required this.label, required this.onPressed, this.icon, this.color, this.busy = false, this.outline = false});
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color? color;
  final bool busy;
  final bool outline;
  @override
  Widget build(BuildContext context) {
    final child = busy
        ? const SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 2.6, color: Colors.white))
        : Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            if (icon != null) Icon(icon, size: 24),
            if (icon != null) const SizedBox(width: 10),
            Flexible(child: Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, letterSpacing: .3))),
          ]);
    if (outline) {
      return SizedBox(
        height: 58,
        width: double.infinity,
        child: OutlinedButton(
          onPressed: busy ? null : onPressed,
          style: OutlinedButton.styleFrom(foregroundColor: color ?? C.ink, side: BorderSide(color: color ?? C.line, width: 1.6), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)), backgroundColor: Colors.white),
          child: child,
        ),
      );
    }
    return SizedBox(
      height: 58,
      width: double.infinity,
      child: DecoratedBox(
        decoration: BoxDecoration(gradient: color == null ? C.grad : null, color: color, borderRadius: BorderRadius.circular(14), boxShadow: [BoxShadow(color: (color ?? C.blue).withValues(alpha: .25), blurRadius: 12, offset: const Offset(0, 4))]),
        child: ElevatedButton(
          onPressed: busy ? null : onPressed,
          style: ElevatedButton.styleFrom(backgroundColor: Colors.transparent, shadowColor: Colors.transparent, foregroundColor: Colors.white, disabledForegroundColor: Colors.white70, disabledBackgroundColor: Colors.transparent, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
          child: child,
        ),
      ),
    );
  }
}

class Kpi extends StatelessWidget {
  const Kpi({super.key, required this.value, required this.label, required this.icon, required this.color});
  final String value, label;
  final IconData icon;
  final Color color;
  @override
  Widget build(BuildContext context) => EvaCard(
        padding: const EdgeInsets.all(12),
        child: Row(children: [
          Container(width: 38, height: 38, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(10)), child: Icon(icon, color: Colors.white, size: 20)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(value, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
              Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted, fontSize: 12)),
            ]),
          ),
        ]),
      );
}

class Pill extends StatelessWidget {
  const Pill(this.text, {super.key, this.color = C.muted});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(99)),
        child: Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12)),
      );
}

Color statusColor(String s) => switch (s) {
      'delivered' => C.green,
      'assigned' || 'loaded' => C.blue,
      'out' || 'en_route' || 'arrived' => C.purple,
      'rescheduled' || 'not_delivered' => C.amber,
      'rto' => C.red,
      _ => C.muted,
    };

class Banner2 extends StatelessWidget {
  const Banner2({super.key, required this.text, required this.color, this.icon = Icons.info_outline, this.action, this.onAction});
  final String text;
  final Color color;
  final IconData icon;
  final String? action;
  final VoidCallback? onAction;
  @override
  Widget build(BuildContext context) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
        decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(12), border: Border.all(color: color.withValues(alpha: .3))),
        child: Row(children: [
          Icon(icon, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w600))),
          if (action != null) TextButton(onPressed: onAction, child: Text(action!)),
        ]),
      );
}

void toast(BuildContext context, String msg, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(msg), backgroundColor: error ? C.red : C.ink, behavior: SnackBarBehavior.floating, duration: Duration(seconds: error ? 5 : 3)));
}
