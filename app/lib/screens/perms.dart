// Location, background-location, notification and battery-optimisation permissions.
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:permission_handler/permission_handler.dart';
import '../i18n.dart';

class PermState {
  bool gpsOn = true;
  bool fine = false;
  bool always = false;
  bool battery = true;
  bool get ok => gpsOn && fine && always;
}

Future<PermState> checkPerms() async {
  final s = PermState();
  s.gpsOn = await Geolocator.isLocationServiceEnabled();
  final p = await Geolocator.checkPermission();
  s.fine = p == LocationPermission.always || p == LocationPermission.whileInUse;
  s.always = p == LocationPermission.always;
  s.battery = await Permission.ignoreBatteryOptimizations.isGranted;
  return s;
}

/// Walks the rider through the permissions, explaining each one first.
Future<PermState> askPerms(BuildContext context) async {
  if (!await Geolocator.isLocationServiceEnabled()) {
    await Geolocator.openLocationSettings();
  }
  var p = await Geolocator.checkPermission();
  if (p == LocationPermission.denied) p = await Geolocator.requestPermission();
  if (p == LocationPermission.deniedForever) {
    await Geolocator.openAppSettings();
  }
  if (p == LocationPermission.whileInUse && context.mounted) {
    final go = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(L.hindi ? 'लोकेशन "हमेशा"' : 'Location "All the time"'),
        content: Text(tr('perm_bg')),
        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('cancel'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('continue')))],
      ),
    );
    if (go == true) {
      final r = await Permission.locationAlways.request();
      if (!r.isGranted) await openAppSettings();
    }
  }
  await Permission.notification.request();
  if (!await Permission.ignoreBatteryOptimizations.isGranted) {
    await Permission.ignoreBatteryOptimizations.request();
  }
  return checkPerms();
}
