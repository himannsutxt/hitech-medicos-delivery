import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../api.dart';
import '../i18n.dart';
import '../logic.dart';
import '../state.dart';
import '../tracker.dart';
import '../ui.dart';

class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.id});
  final int id;
  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> {
  bool _busy = false;
  Delivery? get d => Day.I.deliveries.where((x) => x.id == widget.id).firstOrNull;

  Future<void> _call() async {
    final o = d!;
    unawaited(Api.I.action('deliveries/${o.id}/call', {}));
    await launchUrl(Uri(scheme: 'tel', path: o.mobile));
  }

  Future<void> _navigate() async {
    final o = d!;
    final dest = o.lat != null ? '${o.lat},${o.lng}' : Uri.encodeComponent('${o.address} ${o.landmark}');
    await launchUrl(Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$dest&travelmode=two-wheeler'), mode: LaunchMode.externalApplication);
  }

  Future<void> _event(String type, {Map<String, dynamic> extra = const {}, String? photo, String? photoKind, String? newStatus}) async {
    setState(() => _busy = true);
    final pos = await Tracker.I.fix();
    final r = await Api.I.action('deliveries/${widget.id}/event', {'type': type, 'lat': pos?.latitude, 'lng': pos?.longitude, ...extra}, photoPath: photo, photoKind: photoKind);
    setState(() => _busy = false);
    if (!mounted) return;
    if (r.ok) {
      final st = r.data['delivery'] is Map ? (r.data['delivery']['status'] as String?) : null;
      Day.I.setStatus(widget.id, st ?? newStatus ?? d!.status);
      if (r.queued) toast(context, r.message);
      if (type == 'delivered' && extra['payment_mode'] == 'cash') Day.I.addCash(d!.codAmount);
      if (type == 'delivered' || type == 'failed') {
        toast(context, type == 'delivered' ? (L.hindi ? 'डिलीवर हो गया ✓' : 'Delivered ✓') : (L.hindi ? 'दर्ज किया गया' : 'Recorded'));
        Navigator.of(context).pop();
      }
      unawaited(Day.I.refresh());
    } else {
      toast(context, r.message, error: true);
    }
  }

  Future<void> _arrivedManual() async {
    final ok = await showDialog<bool>(context: context, builder: (c) => AlertDialog(title: Text(tr('mark_arrived_manual')), content: Text(tr('manual_note')), actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('cancel'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('arrived')))]));
    if (ok == true) await _event('arrived', extra: {'manual': true}, newStatus: 'arrived');
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: Day.I,
        builder: (context, _) {
          final o = d;
          if (o == null) return Scaffold(appBar: AppBar(), body: const Center(child: Text('—')));
          final pos = Tracker.I.last;
          final dist = (pos != null && o.lat != null) ? distanceM(pos.latitude, pos.longitude, o.lat!, o.lng!) : null;
          return Scaffold(
            appBar: AppBar(title: Text(o.orderNo), actions: [Padding(padding: const EdgeInsets.only(right: 12), child: Center(child: Pill(o.m['status_label'] as String? ?? o.status, color: statusColor(o.status))))]),
            body: ListView(padding: const EdgeInsets.all(16), children: [
              EvaCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(o.name, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                    if (o.priority == 'urgent') Pill(tr('urgent'), color: C.red),
                    if (o.cold) ...[const SizedBox(width: 6), Pill(tr('cold'), color: C.teal)],
                  ]),
                  const SizedBox(height: 6),
                  Text(o.address, style: const TextStyle(fontSize: 15)),
                  if (o.landmark.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('📍 ${o.landmark}', style: const TextStyle(color: C.muted))),
                  if (dist != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${(dist / 1000).toStringAsFixed(1)} km ${tr('distance')}', style: const TextStyle(color: C.blue, fontWeight: FontWeight.w700))),
                  const Divider(height: 24),
                  Row(children: [
                    o.cod ? Pill('COD ${inr(o.codAmount)}', color: C.amber) : Pill(tr('prepaid'), color: C.green),
                    const SizedBox(width: 8),
                    Text('${o.items} ${tr('items')}', style: const TextStyle(color: C.muted)),
                    const Spacer(),
                    if (o.attempts > 0) Text('${tr('attempt')} ${o.attempts}', style: const TextStyle(color: C.muted)),
                  ]),
                  if (o.notes.isNotEmpty) ...[const SizedBox(height: 10), Banner2(text: '${tr('notes')}: ${o.notes}', color: C.blue, icon: Icons.sticky_note_2_outlined)],
                ]),
              ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(child: BigButton(label: tr('call'), icon: Icons.call, outline: true, color: C.green, onPressed: _call)),
                const SizedBox(width: 10),
                Expanded(child: BigButton(label: tr('navigate'), icon: Icons.navigation, outline: true, color: C.blue, onPressed: _navigate)),
              ]),
              const SizedBox(height: 16),
              ..._buttons(o),
            ]),
          );
        },
      );

  List<Widget> _buttons(Delivery o) {
    switch (o.status) {
      case 'out':
        final other = Day.I.current;
        return [
          if (other != null) Banner2(text: L.hindi ? 'पहले ${other.orderNo} पूरा करें।' : 'Finish ${other.orderNo} first.', color: C.amber),
          BigButton(label: tr('start_delivery'), icon: Icons.play_arrow_rounded, busy: _busy, onPressed: other != null ? null : () => _event('start', newStatus: 'en_route')),
        ];
      case 'en_route':
        return [
          BigButton(label: tr('arrived'), icon: Icons.place, busy: _busy, onPressed: _arrivedManual),
          const SizedBox(height: 10),
          BigButton(label: tr('mark_delivered'), icon: Icons.check_circle, color: C.green, busy: _busy, onPressed: () => _openDeliver(o)),
          const SizedBox(height: 10),
          BigButton(label: tr('cant_deliver'), icon: Icons.cancel, outline: true, color: C.red, onPressed: _busy ? null : () => _openFail(o)),
        ];
      case 'arrived':
        return [
          BigButton(label: tr('mark_delivered'), icon: Icons.check_circle, color: C.green, busy: _busy, onPressed: () => _openDeliver(o)),
          const SizedBox(height: 10),
          BigButton(label: tr('cant_deliver'), icon: Icons.cancel, outline: true, color: C.red, onPressed: _busy ? null : () => _openFail(o)),
        ];
      default:
        return [];
    }
  }

  Future<void> _openDeliver(Delivery o) async {
    final res = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => DeliverSheet(d: o));
    if (res != null) await _event('delivered', extra: Map<String, dynamic>.from(res)..remove('_photo'), photo: res['_photo'] as String?, photoKind: 'pod', newStatus: 'delivered');
  }

  Future<void> _openFail(Delivery o) async {
    final res = await showModalBottomSheet<Map<String, dynamic>>(context: context, isScrollControlled: true, useSafeArea: true, builder: (_) => FailSheet(d: o));
    if (res != null) await _event('failed', extra: Map<String, dynamic>.from(res)..remove('_photo'), photo: res['_photo'] as String?, photoKind: 'failed', newStatus: res['reason'] == 'customer_refused' ? 'rto' : 'rescheduled');
  }
}

Future<String?> takePhoto() async {
  final x = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: 1280, imageQuality: 68);
  return x?.path;
}

class _PhotoBox extends StatelessWidget {
  const _PhotoBox({required this.path, required this.onTake});
  final String? path;
  final VoidCallback onTake;
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTake,
        borderRadius: BorderRadius.circular(14),
        child: Container(
          height: 140,
          decoration: BoxDecoration(color: C.bg, borderRadius: BorderRadius.circular(14), border: Border.all(color: C.line, width: 1.5)),
          clipBehavior: Clip.antiAlias,
          child: path == null
              ? Column(mainAxisAlignment: MainAxisAlignment.center, children: [const Icon(Icons.photo_camera, size: 36, color: C.blue), const SizedBox(height: 6), Text(tr('take_photo'), style: const TextStyle(fontWeight: FontWeight.w700, color: C.blue))])
              : Stack(fit: StackFit.expand, children: [Image.file(File(path!), fit: BoxFit.cover), Positioned(right: 8, bottom: 8, child: Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5), decoration: BoxDecoration(color: Colors.black54, borderRadius: BorderRadius.circular(99)), child: Text(tr('retake'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700))))]),
        ),
      );
}

class DeliverSheet extends StatefulWidget {
  const DeliverSheet({super.key, required this.d});
  final Delivery d;
  @override
  State<DeliverSheet> createState() => _DeliverSheetState();
}

class _DeliverSheetState extends State<DeliverSheet> {
  final _otp = TextEditingController();
  final _utr = TextEditingController();
  final _rcv = TextEditingController();
  String? _photo;
  String _mode = 'cash';
  String? _err;

  bool get _photoNeeded => (Api.I.settings['photo_on_delivery'] ?? true) == true && _otp.text.length != 4;

  void _submit() {
    final d = widget.d;
    if (d.otpRequired && _otp.text.length != 4 && _photo == null) {
      setState(() => _err = L.hindi ? 'OTP डालें, या OTP न आने पर फोटो लें।' : 'Enter the OTP, or take a photo if the OTP did not arrive.');
      return;
    }
    if (_photoNeeded && _photo == null) {
      setState(() => _err = tr('photo_needed'));
      return;
    }
    if (d.cod && _mode == 'upi' && !RegExp(r'^[A-Za-z0-9]{6,30}$').hasMatch(_utr.text.trim())) {
      setState(() => _err = L.hindi ? 'UPI का UTR नंबर डालें।' : 'Enter the UPI transaction ID (UTR).');
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      if (_otp.text.length == 4) 'otp': _otp.text,
      if (_rcv.text.trim().isNotEmpty) 'received_by': _rcv.text.trim(),
      if (d.cod) 'payment_mode': _mode,
      if (d.cod) 'amount': d.codAmount,
      if (d.cod && _mode == 'upi') 'upi_ref': _utr.text.trim(),
      '_photo': _photo,
    });
  }

  @override
  Widget build(BuildContext context) {
    final d = widget.d;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 8, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: C.line, borderRadius: BorderRadius.circular(9)))),
          const SizedBox(height: 14),
          Text('${tr('mark_delivered')} · ${d.orderNo}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 14),
          if (d.cod) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: C.amber.withValues(alpha: .1), borderRadius: BorderRadius.circular(14)),
              child: Column(children: [Text(tr('collect'), style: const TextStyle(color: C.muted)), Text(inr(d.codAmount), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w900))]),
            ),
            const SizedBox(height: 10),
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'cash', label: Text(tr('cash')), icon: const Icon(Icons.payments)), ButtonSegment(value: 'upi', label: Text(tr('upi')), icon: const Icon(Icons.qr_code))],
              selected: {_mode},
              onSelectionChanged: (s) => setState(() => _mode = s.first),
            ),
            if (_mode == 'upi') ...[const SizedBox(height: 10), TextField(controller: _utr, textCapitalization: TextCapitalization.characters, decoration: InputDecoration(labelText: tr('utr')))],
            const SizedBox(height: 14),
          ],
          if (d.otpRequired) ...[
            TextField(controller: _otp, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(4)], style: const TextStyle(fontSize: 24, letterSpacing: 10, fontWeight: FontWeight.w800), decoration: InputDecoration(labelText: tr('otp'), helperText: tr('otp_help')), onChanged: (_) => setState(() {})),
            Align(alignment: Alignment.centerRight, child: TextButton.icon(onPressed: () async {
              final r = await Api.I.call('POST', 'deliveries/${d.id}/otp/resend', {});
              if (context.mounted) toast(context, r.message, error: !r.ok);
            }, icon: const Icon(Icons.refresh), label: Text(tr('resend_otp')))),
          ],
          _PhotoBox(path: _photo, onTake: () async {
            final p = await takePhoto();
            if (p != null) setState(() => _photo = p);
          }),
          const SizedBox(height: 12),
          TextField(controller: _rcv, textCapitalization: TextCapitalization.words, decoration: InputDecoration(labelText: tr('received_by'))),
          if (_err != null) Padding(padding: const EdgeInsets.only(top: 12), child: Banner2(text: _err!, color: C.red, icon: Icons.error_outline)),
          const SizedBox(height: 16),
          BigButton(label: tr('confirm'), icon: Icons.check, color: C.green, onPressed: _submit),
        ]),
      ),
    );
  }
}

class FailSheet extends StatefulWidget {
  const FailSheet({super.key, required this.d});
  final Delivery d;
  @override
  State<FailSheet> createState() => _FailSheetState();
}

class _FailSheetState extends State<FailSheet> {
  String? _reason;
  final _remark = TextEditingController();
  String? _photo;
  DateTime? _date;
  String? _err;

  Map<String, String> get _reasons {
    final r = Api.I.settings['fail_reasons'];
    if (r is Map) return r.map((k, v) => MapEntry(k as String, v as String));
    return {'customer_unavailable': 'Customer unavailable at address', 'not_reachable': 'Customer phone not reachable', 'customer_refused': 'Customer refused', 'reschedule_request': 'Customer asked to reschedule', 'wrong_address': 'Wrong or incomplete address', 'cash_not_ready': 'COD cash not ready', 'other': 'Other'};
  }

  void _submit() {
    if (_reason == null) {
      setState(() => _err = tr('reason'));
      return;
    }
    if (_reason == 'other' && _remark.text.trim().length < 10) {
      setState(() => _err = tr('remark_required'));
      return;
    }
    if (_reason == 'reschedule_request' && _date == null) {
      setState(() => _err = tr('new_date'));
      return;
    }
    final needPhoto = (Api.I.settings['photo_on_failure'] ?? true) == true && _reason != 'not_reachable';
    if (needPhoto && _photo == null) {
      setState(() => _err = tr('photo_needed'));
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      'reason': _reason,
      if (_remark.text.trim().isNotEmpty) 'remark': _remark.text.trim(),
      if (_date != null) 'reschedule_date': DateFormat('yyyy-MM-dd').format(_date!),
      '_photo': _photo,
    });
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(20, 8, 20, MediaQuery.of(context).viewInsets.bottom + 20),
        child: SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: C.line, borderRadius: BorderRadius.circular(9)))),
            const SizedBox(height: 14),
            Text(tr('reason'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            ..._reasons.entries.map((e) => RadioListTile<String>(value: e.key, groupValue: _reason, onChanged: (v) => setState(() => _reason = v), title: Text(e.value), dense: true, contentPadding: EdgeInsets.zero)),
            if (_reason == 'reschedule_request')
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.event),
                  label: Text(_date == null ? tr('new_date') : DateFormat('dd-MM-yyyy').format(_date!)),
                  onPressed: () async {
                    final now = DateTime.now();
                    final p = await showDatePicker(context: context, firstDate: now, lastDate: now.add(const Duration(days: 30)), initialDate: now.add(const Duration(days: 1)));
                    if (p != null) setState(() => _date = p);
                  },
                ),
              ),
            TextField(controller: _remark, maxLines: 2, decoration: InputDecoration(labelText: tr('remark'))),
            const SizedBox(height: 12),
            _PhotoBox(path: _photo, onTake: () async {
              final p = await takePhoto();
              if (p != null) setState(() => _photo = p);
            }),
            if (_err != null) Padding(padding: const EdgeInsets.only(top: 12), child: Banner2(text: _err!, color: C.red, icon: Icons.error_outline)),
            const SizedBox(height: 16),
            BigButton(label: tr('confirm'), icon: Icons.check, color: C.red, onPressed: _submit),
          ]),
        ),
      );
}
