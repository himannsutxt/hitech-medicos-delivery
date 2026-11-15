import 'package:flutter_test/flutter_test.dart';
import 'package:hitech_delivery/logic.dart';

void main() {
  test('distance ~1.11 km for 0.01 deg latitude', () {
    expect(distanceM(28.4595, 77.0266, 28.4695, 77.0266), closeTo(1112, 5));
  });

  test('adaptive GPS interval', () {
    expect(intervalFor(10), 15); // 36 km/h
    expect(intervalFor(2), 30); // 7.2 km/h
    expect(intervalFor(0.2), 180);
  });

  test('arrival needs two fixes inside, or one while stopped', () {
    final d = ArrivalDetector(radiusM: 150);
    expect(d.feed(lat: 28.47, lng: 77.03, custLat: 28.4705, custLng: 77.03, speedMs: 6), false);
    expect(d.feed(lat: 28.4704, lng: 77.03, custLat: 28.4705, custLng: 77.03, speedMs: 6), true);
    d.reset();
    expect(d.feed(lat: 28.4704, lng: 77.03, custLat: 28.4705, custLng: 77.03, speedMs: 0.5), true);
    d.reset();
    expect(d.feed(lat: 28.50, lng: 77.03, custLat: 28.4705, custLng: 77.03, speedMs: 0), false);
    expect(d.feed(lat: 28.4704, lng: 77.03, custLat: 28.4705, custLng: 77.03, speedMs: 0, accuracy: 200), false);
  });

  test('INR formatting with Indian grouping', () {
    expect(inr(184500), '₹1,84,500.00');
    expect(inr(999.5), '₹999.50');
    expect(inr(1234567, decimals: false), '₹12,34,567');
    expect(inr(0), '₹0.00');
  });

  test('server address normalisation (https only)', () {
    expect(normaliseServer('delivery.hitechmedicos.in'), 'https://delivery.hitechmedicos.in/api.php');
    expect(normaliseServer('https://x.in/hmd/'), 'https://x.in/hmd/api.php');
    expect(normaliseServer('https://x.in/hmd/api.php'), 'https://x.in/hmd/api.php');
    expect(normaliseServer('http://x.in'), null);
    expect(normaliseServer(''), null);
  });

  test('retryable statuses', () {
    expect(isRetryable(0), true);
    expect(isRetryable(503), true);
    expect(isRetryable(409), false);
    expect(isRetryable(401), false);
  });
}
