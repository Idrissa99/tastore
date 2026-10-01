import 'package:tontine_achat_store/core/network/api_client.dart';
import 'package:tontine_achat_store/features/notifications/notification.dart';

/// Accès aux notifications (`GET /notifications`).
///
/// Le paginateur est celui de Laravel, page 20 : la liste affichée ici est donc
/// la première page, et le compteur « non lues » ne porte QUE sur elle. Le
/// dire évite de promettre un total que l'écran ne peut pas montrer.
class NotificationRepository {
  const NotificationRepository({required ApiClient api}) : _api = api;

  final ApiClient _api;

  Future<List<AppNotification>> index({int page = 1}) async {
    final response = await _api.get('/notifications', query: {'page': page});

    final data = response is Map ? response['data'] : null;
    if (data is! List) return const [];

    return data
        .whereType<Map>()
        .map((item) => AppNotification.fromJson(Map<String, dynamic>.from(item)))
        .toList(growable: false);
  }

  Future<void> markAsRead(int id) => _api.post('/notifications/$id/read');

  Future<void> markAllAsRead() => _api.post('/notifications/mark-all-read');
}
