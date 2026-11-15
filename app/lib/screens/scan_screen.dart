import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import '../api.dart';
import '../i18n.dart';
import '../state.dart';
import '../tracker.dart';
import '../ui.dart';

class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key});
  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  final _ctrl = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);
  final _manual = TextEditingController();
  String? _last;
  DateTime _lastAt = DateTime(2000);
  String? _msg;
  Color _color = C.muted;
  bool _busy = false;

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _send(String code) async {
    code = code.trim();
    if (code.isEmpty || _busy) return;
    if (code == _last && DateTime.now().difference(_lastAt).inSeconds < 3) return;
    _last = code;
    _lastAt = DateTime.now();
    setState(() => _busy = true);
    final pos = Tracker.I.last;
    final r = await Api.I.call('POST', 'scan', {'code': code, 'lat': pos?.latitude, 'lng': pos?.longitude});
    setState(() => _busy = false);
    final res = r.data['result'] as String?;
    setState(() {
      _msg = r.ok ? ((L.hindi ? r.data['message_hi'] : r.data['message']) as String? ?? code) : r.message;
      _color = res == 'loaded' ? C.green : (res == 'already' ? C.blue : C.red);
    });
    if (res == 'loaded') {
      final d = Day.I.deliveries.where((x) => x.orderNo == code).firstOrNull;
      if (d != null) Day.I.setStatus(d.id, 'loaded');
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('scan'))),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(16),
            child: SizedBox(
              height: 300,
              child: MobileScanner(controller: _ctrl, onDetect: (cap) {
                final v = cap.barcodes.isNotEmpty ? cap.barcodes.first.rawValue : null;
                if (v != null) _send(v);
              }),
            ),
          ),
          const SizedBox(height: 8),
          Text(tr('scan_hint'), textAlign: TextAlign.center, style: const TextStyle(color: C.muted)),
          const SizedBox(height: 12),
          if (_msg != null) Banner2(text: _msg!, color: _color, icon: _color == C.green ? Icons.check_circle : Icons.info_outline),
          Row(children: [
            Expanded(child: TextField(controller: _manual, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('type_code')), onSubmitted: _send)),
            const SizedBox(width: 8),
            SizedBox(height: 54, child: FilledButton(onPressed: _busy ? null : () => _send(_manual.text), child: const Icon(Icons.send))),
          ]),
          const SizedBox(height: 16),
          ListenableBuilder(
            listenable: Day.I,
            builder: (_, __) {
              final left = Day.I.deliveries.where((d) => d.status == 'assigned').toList();
              if (left.isEmpty) return const SizedBox.shrink();
              return EvaCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${left.length} ${tr('left_to_scan')}', style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  ...left.map((d) => Padding(padding: const EdgeInsets.symmetric(vertical: 3), child: Text('• ${d.orderNo} — ${d.name}'))),
                ]),
              );
            },
          ),
        ]),
      );
}
