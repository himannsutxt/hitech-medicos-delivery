// Local storage: an outbox of pending actions and a queue of GPS points (SQLite).
import 'dart:convert';
import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

class Store {
  Store._();
  static final Store I = Store._();
  Database? _db;

  Future<Database> get db async {
    _db ??= await openDatabase(p.join(await getDatabasesPath(), 'hmd.db'), version: 1, onCreate: (d, v) async {
      await d.execute('CREATE TABLE outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, body TEXT NOT NULL, photo_path TEXT, photo_kind TEXT, created INTEGER NOT NULL, tries INTEGER NOT NULL DEFAULT 0)');
      await d.execute('CREATE TABLE gps (id INTEGER PRIMARY KEY AUTOINCREMENT, point TEXT NOT NULL)');
      await d.execute('CREATE TABLE problems (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, created INTEGER NOT NULL)');
    });
    return _db!;
  }

  Future<int> enqueue(String path, Map<String, dynamic> body, {String? photoPath, String? photoKind}) async =>
      (await db).insert('outbox', {'path': path, 'body': jsonEncode(body), 'photo_path': photoPath, 'photo_kind': photoKind, 'created': DateTime.now().millisecondsSinceEpoch});

  Future<List<Map<String, Object?>>> outbox({int limit = 50}) async => (await db).query('outbox', orderBy: 'id', limit: limit);

  Future<void> updateBody(int id, Map<String, dynamic> body) async => (await db).update('outbox', {'body': jsonEncode(body), 'photo_path': null}, where: 'id=?', whereArgs: [id]);

  Future<void> remove(int id) async => (await db).delete('outbox', where: 'id=?', whereArgs: [id]);

  Future<void> bumpTries(int id) async => (await db).rawUpdate('UPDATE outbox SET tries=tries+1 WHERE id=?', [id]);

  Future<int> pendingCount() async => Sqflite.firstIntValue(await (await db).rawQuery('SELECT COUNT(*) FROM outbox')) ?? 0;

  Future<void> addPoint(Map<String, dynamic> pt) async => (await db).insert('gps', {'point': jsonEncode(pt)});

  Future<List<Map<String, Object?>>> points({int limit = 200}) async => (await db).query('gps', orderBy: 'id', limit: limit);

  Future<void> removePoints(int maxId) async => (await db).delete('gps', where: 'id<=?', whereArgs: [maxId]);

  Future<int> pointCount() async => Sqflite.firstIntValue(await (await db).rawQuery('SELECT COUNT(*) FROM gps')) ?? 0;

  Future<void> addProblem(String m) async => (await db).insert('problems', {'message': m, 'created': DateTime.now().millisecondsSinceEpoch});

  Future<List<Map<String, Object?>>> problems() async => (await db).query('problems', orderBy: 'id DESC', limit: 30);

  Future<void> clearProblems() async => (await db).delete('problems');
}
