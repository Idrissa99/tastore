import 'package:intl/date_symbol_data_local.dart';
import 'package:intl/intl.dart';

/// Formatage centralisé : montants en FCFA, dates, durées.
///
/// Les montants arrivent de l'API sous forme de chaînes décimales
/// (« 412.00 »). Tout est converti en nombre avant affichage.
class Fmt {
  const Fmt._();

  /// Charge les noms de mois, une fois pour toutes.
  ///
  /// `DateFormat('…', 'fr_FR')` lève `LocaleDataException : Locale data has not
  /// been initialized` tant que ces données ne sont pas là : le paquet `intl` ne
  /// les embarque pas — elles pèsent trop pour un binaire mobile — et attend un
  /// appel explicite. Sans lui, tout écran affichant une date lève, et rend
  /// « Locale data has not been initialized » à la place de son contenu.
  ///
  /// **Pourquoi ici, et pas dans `main()`.** `main` serait le réflexe, et
  /// insuffisant : le formateur est la seule garantie que le formatage
  /// fonctionne, quel que soit le point d'entrée — un test ne passe pas par
  /// `main`, et l'ordre des `static final` n'y est garanti par rien. Chaque
  /// `DateFormat` passe donc par [_dateFormat], qui charge d'abord. C'est deux
  /// lignes de plus qu'un appel dans `main`, et la différence est qu'aucun
  /// appelant futur ne peut réintroduire le bug.
  ///
  /// L'échec est toléré et mémorisé : mieux vaut une date en `en` — donc
  /// affichée, albeit dans la mauvaise langue — qu'un écran entier mort.
  static bool _ensureDateLocales() {
    try {
      initializeDateFormatting();

      return true;
    } on Object {
      return false;
    }
  }

  /// Construit un `DateFormat` français, données de locale guarantees.
  static DateFormat _dateFormat(String pattern) {
    _ensureDateLocales();

    return DateFormat(pattern, 'fr_FR');
  }

  static final NumberFormat _integer = NumberFormat('#,##0', 'fr_FR');
  static final NumberFormat _twoDecimals = NumberFormat('#,##0.00', 'fr_FR');
  static final DateFormat _dateShort = _dateFormat('d MMM yyyy');
  static final DateFormat _dateLong = _dateFormat('d MMMM yyyy');
  static final DateFormat _dateTime = _dateFormat('d MMM yyyy · HH:mm');
  static final DateFormat _numeric = _dateFormat('dd/MM/yyyy');

  /// Convertit une valeur API (nombre ou chaîne décimale) en nombre.
  ///
  /// `'412.00'.parse()` fonctionne, mais `'1 250,00'` non : on tolère les
  /// deux formats, ainsi que les espaces (normale, insécable ou fine) que
  /// Laravel peut produire selon la locale du serveur.
  static double toDouble(Object? value) {
    if (value == null) return 0;
    if (value is num) return value.toDouble();

    // Chaîne BRUTE impérative. Sur une chaîne normale, `\s` n'est pas une
    // séquence d'échappement valide en Dart : l'analyseur compiles la classe en
    // `[s…]`, où `s` est une LETTRE et non un espace. L'espace ASCII (U+0020)
    // disparaissait alors de la classe, et « 1 250,00 » arrivait jusqu'à
    // double.tryParse() avec son espace : parsing raté, retour 0. Autrement
    // dit, tout montant écrit avec un séparateur de milliers valait zéro.
    // Les espaces Unicode sont listés en plus de `\s` pour que l'intention
    // reste lisible et ne dépende pas des règles d'\s selon le moteur.
    final raw = value
        .toString()
        .replaceAll(RegExp(r'[\s   ]'), '')
        .trim();
    if (raw.isEmpty) return 0;

    final normalized = raw.contains(',') && !raw.contains('.')
        ? raw.replaceFirst(',', '.')
        : raw;
    return double.tryParse(normalized) ?? 0;
  }


  static int toInt(Object? value) => toDouble(value).round();

  /// « 5 000 FCFA »
  ///
  /// Le séparateur de milliers est une espace insécable fine (U+202F),
  /// comme l'exige la typographie française.
  static String fcfa(Object? value, {bool withCurrency = true, bool decimals = false}) {
    final amount = toDouble(value);
    final formatted = (decimals ? _twoDecimals : _integer).format(amount);
    return withCurrency ? '$formatted FCFA' : formatted;
  }

  /// Version compacte pour les tuiles de statistiques : « 1,2 M ».
  static String fcfaCompact(Object? value) {
    final amount = toDouble(value);
    if (amount.abs() >= 1000000) {
      return '${_decimal1.format(amount / 1000000)} M FCFA';
    }
    if (amount.abs() >= 10000) {
      return '${_integer.format(amount / 1000)} k FCFA';
    }
    return fcfa(amount);
  }

  static final NumberFormat _decimal1 = NumberFormat('#,##0.#', 'fr_FR');

  static String number(Object? value) => _integer.format(toDouble(value));

  /// Convertit 0.03 en « 3 % » et 3 en « 3 % ».
  static String percent(Object? value, {int decimals = 0}) {
    final parsed = toDouble(value);
    final asPercent = parsed <= 1 ? parsed * 100 : parsed;
    final formatted = asPercent.toStringAsFixed(decimals);
    // Remplace le point décimal par la virgule française.
    final localized = decimals == 0 ? formatted : formatted.replaceFirst('.', ',');
    return '$localized %';
  }

  /// Initiales pour les avatars (2 lettres maximum).
  static String initials(String? name) {
    final trimmed = (name ?? '').trim();
    if (trimmed.isEmpty) return '?';
    final parts = trimmed.split(RegExp(r'\s+')).where((part) => part.isNotEmpty).take(2);
    return parts.map((part) => part[0].toUpperCase()).join();
  }

  static String capitalize(String? value) {
    if (value == null || value.isEmpty) return '';
    return value[0].toUpperCase() + value.substring(1);
  }

  /// Pluriel simple : `plural(2, 'tontine')` → « 2 tontines ».
  ///
  /// Les accolades autour de [number] ne sont pas une preference de style :
  /// sans elles, Dart interprète `$number` comme la FONCTION `number` et
  /// interpole sa représentation — « Closure: (Object?) => String from
  /// Function 'number' » — au lieu de son résultat. L'écran affichait alors un
  /// texte de plusieurs centaines de caractères à la place d'un compteur.
  static String plural(int count, String singular, [String? plural]) =>
      '${number(count)} ${count > 1 ? (plural ?? '${singular}s') : singular}';

  /* ------------------------------------------------------------------ */
  /* Dates                                                               */
  /* ------------------------------------------------------------------ */

  static DateTime? parseDate(Object? value) {
    if (value == null) return null;
    if (value is DateTime) return value;
    return DateTime.tryParse(value.toString());
  }

  /// « 5 oct. 2026 »
  static String date(Object? value) {
    final parsed = parseDate(value);
    return parsed == null ? '—' : _dateShort.format(parsed);
  }

  /// « 02 octobre 2026 »
  static String dateLong(Object? value) {
    final parsed = parseDate(value);
    return parsed == null ? '—' : _dateLong.format(parsed);
  }

  /// « 02/10/2026 »
  static String dateNumeric(Object? value) {
    final parsed = parseDate(value);
    return parsed == null ? '—' : _numeric.format(parsed);
  }

  /// « 02 oct. 2026 · 14:35 »
  static String dateTime(Object? value) {
    final parsed = parseDate(value);
    return parsed == null ? '—' : _dateTime.format(parsed);
  }

  /// « il y a 3 jours », « dans 2 h »
  static String relative(Object? value) {
    final parsed = parseDate(value);
    if (parsed == null) return '—';

    final diff = parsed.difference(DateTime.now());
    final future = diff.isNegative ? -diff : diff;
    final past = diff.isNegative;

    String phrase;
    if (future.inSeconds < 60) {
      phrase = "à l'instant";
    } else if (future.inMinutes < 60) {
      phrase = '${future.inMinutes} min';
    } else if (future.inHours < 24) {
      phrase = '${future.inHours} h';
    } else if (future.inDays < 7) {
      phrase = plural(future.inDays, 'jour');
    } else if (future.inDays < 31) {
      phrase = plural(future.inDays ~/ 7, 'semaine');
    } else if (future.inDays < 365) {
      phrase = plural(future.inDays ~/ 30, 'mois');
    } else {
      phrase = plural(future.inDays ~/ 365, 'an');
    }

    return past ? 'il y a $phrase' : 'dans $phrase';
  }

  /// Nombre de jours calendaires entre aujourd'hui et une date
  /// (0 = aujourd'hui). Indispensable pour les échéances.
  static int? daysUntil(Object? value) {
    final parsed = parseDate(value);
    if (parsed == null) return null;
    final today = DateTime.now();
    final from = DateTime(today.year, today.month, today.day);
    final to = DateTime(parsed.year, parsed.month, parsed.day);
    return to.difference(from).inDays;
  }

  /// « aujourd'hui », « demain », « dans 3 jours » — orienté échéance.
  static String countdown(Object? value) {
    final days = daysUntil(value);
    if (days == null) return '—';
    if (days == 0) return "aujourd'hui";
    if (days == 1) return 'demain';
    if (days == -1) return 'hier';
    return days > 0 ? 'dans $days jours' : 'il y a ${-days} jours';
  }

  /// « 2 mois » à partir d'une date ISO.
  static String monthYear(Object? value) {
    final parsed = parseDate(value);

    return parsed == null ? '—' : _dateFormat('MMMM yyyy').format(parsed);
  }

  /// Coupe un texte long en conservant la fin (utile pour les références).
  static String truncate(String? value, int max) {
    if (value == null || value.isEmpty) return '';
    if (value.length <= max) return value;
    return '${value.substring(0, max - 1)}…';
  }

  /// Retire tout sauf lettres et chiffres (codes de transfert).
  static String normalizeTransferCode(String? value) =>
      (value ?? '').replaceAll(RegExp(r'[^A-Za-z0-9]'), '').toUpperCase();
}
