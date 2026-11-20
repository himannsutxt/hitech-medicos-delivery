// Pure helpers with no Flutter dependencies (unit-tested in test/logic_test.dart).
import 'dart:math' as math;

const double _earthR = 6371000.0;

double distanceM(double lat1, double lng1, double lat2, double lng2) {
  final p1 = lat1 * math.pi / 180, p2 = lat2 * math.pi / 180;
  final dp = (lat2 - lat1) * math.pi / 180, dl = (lng2 - lng1) * math.pi / 180;
  final a = math.pow(math.sin(dp / 2), 2) + math.cos(p1) * math.cos(p2) * math.pow(math.sin(dl / 2), 2);
  return 2 * _earthR * math.asin(math.min(1.0, math.sqrt(a)));
}

/// Seconds between GPS points for the current speed (m/s), per spec.
int intervalFor(double speedMs, {int moving = 15, int slow = 30, int stationary = 180}) {
  final kmh = speedMs * 3.6;
  if (kmh > 20) return moving;
  if (kmh >= 3) return slow;
  return stationary;
}

/// Arrival needs two consecutive fixes inside the radius, or one inside while nearly stopped.
class ArrivalDetector {
  ArrivalDetector({required this.radiusM});
  final double radiusM;
  int _insideCount = 0;

  bool feed({required double lat, required double lng, required double custLat, required double custLng, required double speedMs, double accuracy = 10}) {
    if (accuracy > 75) return false;
    final inside = distanceM(lat, lng, custLat, custLng) <= radiusM;
    if (!inside) {
      _insideCount = 0;
      return false;
    }
    _insideCount++;
    return _insideCount >= 2 || speedMs * 3.6 < 5;
  }

  void reset() => _insideCount = 0;
}

String inr(num v, {bool decimals = true}) {
  final neg = v < 0;
  final abs = v.abs();
  final whole = decimals ? abs.floor() : abs.round();
  final paise = ((abs - abs.floor()) * 100).round();
  var s = whole.toString();
  if (s.length > 3) {
    final last3 = s.substring(s.length - 3);
    var rest = s.substring(0, s.length - 3);
    final parts = <String>[];
    while (rest.length > 2) {
      parts.insert(0, rest.substring(rest.length - 2));
      rest = rest.substring(0, rest.length - 2);
    }
    if (rest.isNotEmpty) parts.insert(0, rest);
    s = '${parts.join(',')},$last3';
  }
  return '${neg ? '-' : ''}₹$s${decimals ? '.${paise.toString().padLeft(2, '0')}' : ''}';
}

/// Validates the server address the manager gives the rider.
String? normaliseServer(String input) {
  var s = input.trim();
  if (s.isEmpty) return null;
  if (!s.startsWith('http://') && !s.startsWith('https://')) s = 'https://$s';
  final u = Uri.tryParse(s);
  if (u == null || u.host.isEmpty || u.scheme != 'https') return null;
  var path = u.path;
  if (!path.endsWith('api.php')) {
    path = '${path.endsWith('/') ? path : '$path/'}api.php';
  }
  return Uri(scheme: 'https', host: u.host, port: u.hasPort ? u.port : null, path: path).toString();
}

/// Errors worth retrying later (offline / server busy) vs. rejected by the server.
bool isRetryable(int status) => status == 0 || status == 408 || status == 429 || status >= 500;

/// App version reported to the server (shown in the website's App health panel).
const String kAppVersion = '1.2.0';
