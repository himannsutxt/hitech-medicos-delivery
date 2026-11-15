import 'package:flutter/material.dart';
import '../api.dart';
import '../i18n.dart';
import '../logic.dart';
import '../state.dart';
import '../ui.dart';

class CodScreen extends StatefulWidget {
  const CodScreen({super.key});
  @override
  State<CodScreen> createState() => _CodScreenState();
}

class _CodScreenState extends State<CodScreen> {
  bool _busy = false;

  Future<void> _deposit() async {
    setState(() => _busy = true);
    await Api.I.sync();
    final r = await Api.I.call('POST', 'cod/deposit', {});
    setState(() => _busy = false);
    if (!mounted) return;
    toast(context, r.message, error: !r.ok);
    await Day.I.refresh();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('cod'))),
        body: ListenableBuilder(
          listenable: Day.I,
          builder: (context, _) {
            final d = Day.I;
            final cods = d.deliveries.where((x) => x.cod && x.status == 'delivered').toList();
            final upi = (d.summary['cod_upi'] as num?)?.toDouble() ?? 0;
            return RefreshIndicator(
              onRefresh: d.refresh,
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Container(
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(gradient: C.grad, borderRadius: BorderRadius.circular(18)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('cash_in_hand'), style: const TextStyle(color: Colors.white70)),
                    const SizedBox(height: 4),
                    Text(inr(d.cash), style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w900)),
                    const SizedBox(height: 6),
                    Text('UPI: ${inr(upi)}', style: const TextStyle(color: Colors.white)),
                    if (d.codLimit > 0) Text('${L.hindi ? 'सीमा' : 'Limit'}: ${inr(d.codLimit, decimals: false)}', style: const TextStyle(color: Colors.white70)),
                  ]),
                ),
                const SizedBox(height: 14),
                if (d.pendingSettlement) Banner2(text: tr('waiting_accounts'), color: C.amber, icon: Icons.hourglass_top),
                if (d.cash > 0) ...[
                  BigButton(label: tr('deposit_cod'), icon: Icons.payments, color: C.teal, busy: _busy, onPressed: _deposit),
                  const SizedBox(height: 6),
                  Text(tr('deposit_help'), textAlign: TextAlign.center, style: const TextStyle(color: C.muted)),
                ],
                const SizedBox(height: 18),
                Text(tr('today_collections'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                const SizedBox(height: 8),
                if (cods.isEmpty) const Padding(padding: EdgeInsets.all(20), child: Center(child: Text('—', style: TextStyle(color: C.muted)))),
                ...cods.map((x) => Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: EvaCard(child: Row(children: [
                        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(x.orderNo, style: const TextStyle(fontWeight: FontWeight.w800)), Text(x.name, style: const TextStyle(color: C.muted))])),
                        Text(inr(x.codAmount), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                      ])),
                    )),
              ]),
            );
          },
        ),
      );
}
