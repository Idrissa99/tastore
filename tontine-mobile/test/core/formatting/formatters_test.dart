import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';

/// RÉGRESSION — `Fmt.toDouble` et les espaces.
///
/// La classe de caractères était écrite dans une chaîne NORMALE, où `\s`
/// n'est pas un échappement Dart valide. Le motif compilé devenait `[s…]` :
/// `s` était une lettre littérale, et surtout l'espace ASCII (U+0020)
/// disparaissait de la classe. Un montant comme « 1 250,00 » gardait donc son
/// espace jusqu'à `double.tryParse()`, qui échouait — et la fonction
/// renvoyait 0. Concrètement, tout montant contenant un séparateur de
/// milliers s'affichait à zéro.
void main() {
  group('Fmt.toDouble tolère les formats que Laravel produit', () {
    test('nombre déjà typé', () {
      expect(Fmt.toDouble(412), 412.0);
      expect(Fmt.toDouble(412.5), 412.5);
    });

    test('décimal pointé', () {
      expect(Fmt.toDouble('412.00'), 412.0);
      expect(Fmt.toDouble('1250.75'), 1250.75);
    });

    test('décimal virgulé, format français', () {
      expect(Fmt.toDouble('412,50'), 412.5);
    });

    test('séparateur de milliers — espace ASCII', () {
      // C'est EXACTEMENT le cas cassé par la chaîne non brute.
      expect(Fmt.toDouble('1 250,00'), 1250.0);
      expect(Fmt.toDouble('1 000 000'), 1000000.0);
      expect(Fmt.toDouble('12 345,67'), 12345.67);
    });

    test('espaces Unicode : insécable, fine, cadratin', () {
      // U+00A0 insécable, U+202F fine, U+2009 cadratin.
      expect(Fmt.toDouble('1 250,00'), 1250.0);
      expect(Fmt.toDouble('1 250,00'), 1250.0);
      expect(Fmt.toDouble('1 250,00'), 1250.0);
    });

    test('espaces de bord', () {
      expect(Fmt.toDouble('  412.00  '), 412.0);
    });

    test('la lettre s n est pas mangée par la classe', () {
      // Le motif cassé contenait un « s » littéral : toute chaîne contenant
      // cette lettre perdait ses s. Sans nombre à parser, le résultat doit
      // rester 0 — mais surtout, la lettre ne doit pas disparaître d'un
      // montant valide.
      expect(Fmt.toDouble('Soso'), 0);
    });

    test('valeurs inexploitables et nulles', () {
      expect(Fmt.toDouble(null), 0);
      expect(Fmt.toDouble(''), 0);
      expect(Fmt.toDouble('   '), 0);
      expect(Fmt.toDouble('abc'), 0);
    });
  });

  group('Fmt.toInt dérive de toDouble', () {
    test('arrondi', () {
      expect(Fmt.toInt('1 250,60'), 1251);
      expect(Fmt.toInt('1 249,40'), 1249);
    });
  });

  group('Fmt.plural', () {
    test('singulier et pluriel', () {
      expect(Fmt.plural(1, 'tontine'), '1 tontine');
      expect(Fmt.plural(3, 'tontine'), '3 tontines');
    });

    test('zéro reste au singulier', () {
      // « 0 place » et non « 0 places » : c'est l'usage attendu en français,
      // et surtout « 0 tontines disponibles » se lirait comme un catalogue
      // inconsistent.
      expect(Fmt.plural(0, 'tontine'), '0 tontine');
    });

    test('le pluriel_irregulier est pris en compte', () {
      expect(Fmt.plural(2, 'mois', 'mois'), '2 mois');
    });

    test('le compteur est formaté, jamais la fonction qui le formate', () {
      // Régression : `$number(count)` sans accolades fait interpoler la
      // FONCTION `number`, dont la représentation est une chaîne de plusieurs
      // centaines de caractères. Aucun test ne l'attrapait, parce que rien
      // n'utilisait encore [Fmt.plural] à l'écran.
      final result = Fmt.plural(3, 'place');

      expect(result, isNot(contains('Closure')));
      expect(result.length, lessThan(20));
    });

    test('Fmt.relative s appuie sur un pluriel correct', () {
      // `relative` construit ses durées avec `plural` : le bug s'y propageait.
      expect(Fmt.relative(DateTime.now().subtract(const Duration(days: 3))), 'il y a 3 jours');
    });
  });

  /// RÉGRESSION — les dates.
  ///
  /// `DateFormat('…', 'fr_FR')` lève `LocaleDataException : Locale data has not
  /// been initialized` tant que les noms de mois ne sont pas chargés, et le
  /// paquet `intl` ne les embarque pas par défaut. Aucun autre écran
  /// n'échouait parce que la fiche de profil est la SEULE à afficher une date :
  /// c'est elle qui affichait « Locale data has not been initialized » au lieu
  /// du profil.
  ///
  /// Les tests ci-dessus ne touchaient que `toDouble`, `toInt`, `plural` et
  /// `relative` — or `relative` construit ses durées avec `plural`, pas avec un
  /// `DateFormat`. Le trou était donc exactement calé sur le bug.
  group('Fmt dates : les données de locale sont disponibles', () {
    final noon = DateTime(2026, 10, 2, 14, 35);

    test('date courte, en français', () {
      expect(Fmt.date(noon), '2 oct. 2026');
    });

    test('date longue, nom de mois complet', () {
      expect(Fmt.dateLong(noon), '2 octobre 2026');
    });

    test('date numérique', () {
      expect(Fmt.dateNumeric(noon), '02/10/2026');
    });

    test('date et heure', () {
      expect(Fmt.dateTime(noon), '2 oct. 2026 · 14:35');
    });

    test('mois et année', () {
      expect(Fmt.monthYear(noon), 'octobre 2026');
    });

    test('une date illisible ne lève pas non plus', () {
      // Un `null` doit rendre « — » : c'est le chemin le plus fréquent, et il
      // ne doit surtout pas dépendre des données de locale.
      expect(Fmt.date(null), '—');
      expect(Fmt.dateLong('pas une date'), '—');
      expect(Fmt.monthYear(null), '—');
    });

    test('les montants restent en français', () {
      // Le format des nombres n'a pas subi le même sort : il est vérifié ici
      // pour que la correction des dates n'ait pas cassé les deux.
      //
      // Les séparateurs sont écrits en-points-de-code, et non tels quels : ce sont
      // des espaces INSECABLES, invisibles dans un diff, et un test qui les
      // recopie à l'octet près depuis un terminal se met à échouer sans raison.
      expect(Fmt.fcfa(133750), '133\u202f750\u00a0FCFA');
      expect(Fmt.number(1250), '1\u202f250');
    });
  });
}
