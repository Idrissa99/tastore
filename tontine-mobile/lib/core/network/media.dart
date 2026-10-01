import 'package:tontine_achat_store/core/config/app_config.dart';

/// URLs de médias renvoyées par le backend.
///
/// Fichier volontairement sans Flutter : ces deux fonctions sont pures et
/// servent aussi bien à l'interface qu'aux modèles. Les garder ici évite
/// qu'un modèle de domaine doive importer un widget pour résoudre un chemin.

/// Hôtes « privés » : ce que le backend peut considérer comme sa propre
/// adresse quand il fabrique une URL absolue.
final _privateHost = RegExp(
  r'^(localhost|127\.\d+\.\d+\.\d+|10\.\d+\.\d+\.\d+|'
  r'192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)$',
  caseSensitive: false,
);

/// Rend exploitable une URL de média renvoyée par le backend.
///
/// Laravel sert les fichiers sous `/storage/...`. Selon sa configuration, il
/// renvoie soit un chemin RELATIF, soit une URL ABSOLUE construite depuis son
/// propre `APP_URL` (`config/filesystems.php`). Les deux cas sont traités.
///
/// **Pourquoi réécrire l'hôte des URL absolues pointant vers un réseau privé.**
/// En développement, `APP_URL` vaut l'adresse Wi-Fi du poste — celle que le
/// backend croit être la sienne. Or cette adresse change au renouvellement du
/// bail DHCP : le backend continue alors de renvoyer
/// `http://192.168.3.106:8000/storage/…` sur une machine devenue
/// `192.168.173.43`, et le fichier EST pourtant servi correctement. L'image
/// disparaît de l'application sans la moindre erreur — un « Pas de photo » qui
/// ment, puisque la photo existe et que le serveur la rend en 200.
///
/// L'application, elle, sait à quelle API elle a été compilée : c'est
/// l'[AppConfig.backendOrigin] de la commande de build, pas d'un `APP_URL`
/// modifiable à l'autre bout du câble. On s'y fie donc quand l'hôte reçu est un
/// réseau privé — signe qu'il s'agit du backend lui-même, non d'un CDN.
///
/// Un hôte « public » (un vrai nom de domaine) n'est **jamais** réécrit : une
/// image hébergée ailleurs — S3, CDN — reste où son auteur l'a mise.
String? mediaUrl(Object? url) {
  if (url == null) return null;

  final value = url.toString().trim();
  if (value.isEmpty) return null;

  // Inline : aucun réseau impliqué, on n'y touche pas.
  if (value.startsWith('data:')) return value;

  if (!value.startsWith('http://') && !value.startsWith('https://')) {
    return '${AppConfig.backendOrigin}${value.startsWith('/') ? '' : '/'}$value';
  }

  final parsed = Uri.tryParse(value);
  if (parsed == null) return value;

  final host = parsed.host;
  if (host.isEmpty || !_privateHost.hasMatch(host)) return value;

  // On ne conserve que le CHEMIN de l'URL reçue — `/storage/…` — et on le
  // rattache à l'origine connue de l'application. Le reste (requête, fragment)
  // est conservé : une URL signée peut en dépendre.
  final path = value.substring(value.indexOf(parsed.path));

  return '${AppConfig.backendOrigin}$path';
}

/// Première image disponible d'un produit, quelle que soit sa forme.
///
/// L'API accepte deux représentations : `image` (champ historique) et
/// `images[]` (tableau `ProductMedia`). Une tontine n'embarque qu'un extrait
/// de produit — `id, name, price, image, merchant_id` — donc seule la première
/// forme y est garantie, là où le catalogue produits voit les deux. On garde
/// les deux lectures : en choisir une et ignorer l'autre ferait disparaître
/// des photos le jour où le backend change de représentation.
String? productImage(Map<String, dynamic>? product) {
  if (product == null) return null;

  final images = product['images'];
  if (images is List && images.isNotEmpty) {
    final first = images.first;
    final url = first is Map ? mediaUrl(first['url']) : mediaUrl(first);
    if (url != null) return url;
  }

  return mediaUrl(product['image']);
}
