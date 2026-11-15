import 'package:flutter/material.dart';
import '../i18n.dart';
import '../logic.dart';
import '../state.dart';
import '../tracker.dart';
import '../ui.dart';
import 'order_screen.dart';

class DeliveriesScreen extends StatelessWidget {
  const DeliveriesScreen({super.key});
  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('deliveries'))),
        body: ListenableBuilder(
          listenable: Day.I,
          builder: (context, _) {
            final list = [...Day.I.deliveries]..sort((a, b) => (a.closed ? 1 : 0).compareTo(b.closed ? 1 : 0));
            return RefreshIndicator(
              onRefresh: Day.I.refresh,
              child: list.isEmpty
                  ? ListView(children: [const SizedBox(height: 120), Center(child: Text(tr('no_orders'), style: const TextStyle(color: C.muted)))])
                  : ListView.separated(
                      padding: const EdgeInsets.all(16),
                      itemCount: list.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder: (c, i) => OrderTile(d: list[i], onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: list[i].id)))),
                    ),
            );
          },
        ),
      );
}

class OrderTile extends StatelessWidget {
  const OrderTile({super.key, required this.d, this.onTap});
  final Delivery d;
  final VoidCallback? onTap;
  @override
  Widget build(BuildContext context) {
    final pos = Tracker.I.last;
    final dist = (pos != null && d.lat != null) ? distanceM(pos.latitude, pos.longitude, d.lat!, d.lng!) : null;
    return Opacity(
      opacity: d.closed ? .6 : 1,
      child: EvaCard(
        onTap: onTap,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text(d.orderNo, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            const SizedBox(width: 8),
            if (d.priority == 'urgent') Pill(tr('urgent'), color: C.red),
            if (d.priority == 'priority') Pill(tr('priority'), color: C.amber),
            if (d.cold) ...[const SizedBox(width: 4), Pill(tr('cold'), color: C.teal)],
            const Spacer(),
            Pill(d.m['status_label'] as String? ?? d.status, color: statusColor(d.status)),
          ]),
          const SizedBox(height: 8),
          Text(d.name, style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 2),
          Text(d.address, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: C.muted)),
          const SizedBox(height: 8),
          Row(children: [
            d.cod ? Pill('COD ${inr(d.codAmount, decimals: false)}', color: C.amber) : Pill(tr('prepaid'), color: C.green),
            const SizedBox(width: 8),
            Text('${d.items} ${tr('items')}', style: const TextStyle(color: C.muted)),
            const Spacer(),
            if (dist != null) Text('${(dist / 1000).toStringAsFixed(1)} km ${tr('distance')}', style: const TextStyle(color: C.muted, fontWeight: FontWeight.w600)),
          ]),
        ]),
      ),
    );
  }
}
