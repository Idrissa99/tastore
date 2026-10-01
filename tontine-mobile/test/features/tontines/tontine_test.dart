import 'package:flutter_test/flutter_test.dart';
import 'package:tontine_achat_store/features/tontines/tontine.dart';

import 'tontine_fixtures.dart';

Tontine parse(Map<String, dynamic> json, {int? currentUserId}) =>
    Tontine.fromJson(json, currentUserId: currentUserId);

void main() {
  group('montants', () {
    test('les chaînes décimales du backend deviennent des nombres', () {
      final tontine = parse(tontineJson());

      // Sans ce cas, `Fmt.toDouble` rendrait 0 et la carte afficherait
      // « 0 FCFA » : le défaut le plus coûteux de l'écran, parce qu'il ne se
      // voit qu'à l'œil.
      expect(tontine.totalAmount, 300000);
      expect(tontine.contributionAmount, 25000);
      expect(tontine.commissionRate, closeTo(0.03, 1e-9));
      expect(tontine.product!.price, 300000);
    });

    test('une valeur absente vaut zéro sans faire échouer le décodage', () {
      final tontine = parse(
        tontineJson(overrides: const {'total_amount': null, 'contribution_amount': null}),
      );

      expect(tontine.totalAmount, 0);
      expect(tontine.contributionAmount, 0);
      // 0/0 ne doit pas produire NaN : une barre de progression à NaN ne
      // s'affiche pas du tout, et la carte disparaît sans explication.
      expect(tontine.fundingRatio, 0);
      expect(tontine.fundingRatio.isNaN, isFalse);
    });
  });

  group('type et produit', () {
    test('une tontine argent n\'a pas de produit et ne plante pas', () {
      final tontine = parse(tontineJson(type: 'cash', product: null));

      expect(tontine.type, TontineType.cash);
      expect(tontine.product, isNull);
      expect(tontine.searchLabel, 'Tontine Téléphone');
    });

    test('une tontine produit lit le nom et la photo du produit', () {
      final tontine = parse(tontineJson());

      expect(tontine.type, TontineType.product);
      expect(tontine.product!.name, 'Smartphone');
      expect(tontine.product!.imageUrl, contains('/storage/products/photo.jpg'));
      expect(tontine.searchLabel, 'Smartphone');
    });

    test('le montant comparé par le filtre dépend du type', () {
      // Un produit s'annonce par son versement, une tontine argent par ce
      // qu'elle vise : comparer le mauvais des deux à un plafond fausserait
      // tout le catalogue.
      expect(parse(tontineJson()).comparableAmount, 25000);
      expect(parse(tontineJson(type: 'cash', product: null)).comparableAmount, 300000);
    });
  });

  group('statuts', () {
    test('chaque statut a son libellé et sa teinte', () {
      expect(TontineStatus.parse('open').label, 'Ouverte');
      expect(TontineStatus.parse('active').label, 'En cours');
      expect(TontineStatus.parse('completed').label, 'Terminée');
      expect(TontineStatus.parse('cancelled').label, 'Annulée');
    });

    test('un statut inconnu reste affiché, plutôt que de disparaître', () {
      // Le prendre pour « ouverte » ferait rejoindre une tontine annulée : le
      // refus du serveur serait alors la seule information fiable.
      final tontine = parse(tontineJson(status: 'archived'));

      expect(tontine.status, TontineStatus.unknown);
      expect(tontine.status.acceptsMembers, isFalse);
      expect(tontine.status.label, 'Statut inconnu');
    });

    test('seule « open » accueille des membres', () {
      expect(TontineStatus.open.acceptsMembers, isTrue);

      final closed = [TontineStatus.active, TontineStatus.completed, TontineStatus.cancelled];
      for (final status in closed) {
        expect(status.acceptsMembers, isFalse, reason: status.name);
      }
    });
  });

  group('adhésion', () {
    test('une tontine ouverte et incomplète invite à adhérer', () {
      expect(parse(tontineJson()).joinAvailability, JoinAvailability.open);
      expect(parse(tontineJson()).joinBlockedReason(), isEmpty);
    });

    test('une tontine complète a son propre motif, distinct de « fermée »', () {
      // Les deux refus ne se réparent pas de la même façon : une tontine
      // pleine est définitive, une tontine démarrée ne l'est pas.
      final full = parse(tontineJson(currentMembers: 12));

      expect(full.joinAvailability, JoinAvailability.full);
      expect(full.joinBlockedReason(), 'Cette tontine est déjà complète.');
      expect(full.isFull, isTrue);
      expect(full.remainingSlots, 0);
    });

    test('une tontine démarrée refuse avec le motif « fermée »', () {
      final started = parse(tontineJson(status: 'active'));

      expect(started.joinAvailability, JoinAvailability.closed);
      expect(started.joinBlockedReason(), contains('n\'accepte plus'));
    });

    test('être membre prime sur tout le reste', () {
      // Une tontine pleine ET déjà rejointe : l'utilisateur n'a rien à faire,
      // et lui parler de places restantes serait absurde.
      final mine = parse(tontineJson(currentMembers: 12, isMember: true));

      expect(mine.joinAvailability, JoinAvailability.alreadyMember);
      expect(mine.joinBlockedReason(), 'Tu es déjà membre de cette tontine.');
    });

    test('les places restantes ne sont jamais négatives', () {
      // Le backend peut envoyer un compteur qui dépasse `max_members` si un
      // membre a quitté entre-temps : « -2 places » n'a aucun sens.
      final tontine = parse(tontineJson(currentMembers: 14, maxMembers: 12));

      expect(tontine.remainingSlots, 0);
      expect(tontine.fillRatio, 1.0);
    });
  });

  group('progression', () {
    test('le montant collecté est le produit des cotisants et du versement', () {
      final tontine = parse(
        tontineJson(
          round: {'number': 2, 'expected_members': 12, 'paid_members': 4, 'is_funded': false},
        ),
      );

      expect(tontine.collectedAmount, 100000);
      expect(tontine.fundingRatio, closeTo(1 / 3, 1e-9));
    });

    test('un round absent ne casse pas l\'affichage', () {
      final tontine = parse(tontineJson(overrides: const {'round': null}));

      expect(tontine.round.paidMembers, 0);
      expect(tontine.round.ratio, 0);
      expect(tontine.collectedAmount, 0);
    });

    test('la progression est bornée à 100 %', () {
      // Une tontine argent continue de cotiser après avoir atteint son objectif :
      // une barre qui déborde est un défaut visible, pas une donnée exacte.
      final overfunded = parse(
        tontineJson(
          type: 'cash',
          product: null,
          totalAmount: '1000.00',
          contributionAmount: '500.00',
          round: {'number': 1, 'expected_members': 12, 'paid_members': 12, 'is_funded': true},
        ),
      );

      expect(overfunded.fundingRatio, 1.0);
    });
  });

  group('membres', () {
    test('la ligne personnelle est repérée quand on connaît son identifiant', () {
      final tontine = parse(tontineJson(), currentUserId: 7);

      expect(tontine.members.single.isMe, isTrue);
    });

    test('hors session, personne n\'est « moi »', () {
      // Marquer arbitrairement le premier membre donnerait l'impression d'être
      // déjà inscrit à une tontine qu'on n'a jamais rejointe.
      expect(parse(tontineJson()).members.single.isMe, isFalse);
    });

    test('la position est absente tant que le tirage n\'a pas eu lieu', () {
      final tontine = parse(tontineJson());

      expect(tontine.rotationRevealed, isFalse);
      expect(tontine.members.single.position, isNull);
    });

    test('la position apparaît une fois le tirage révélé', () {
      final tontine = parse(
        tontineJson(
          rotationRevealed: true,
          members: [
            {
              'id': 1,
              'user_id': 7,
              'user_name': 'Awa Sanou',
              'position': 3,
              'status': 'beneficiary',
              'delivery_status': 'awaiting_payment',
              'beneficiary_round': 2,
              'delivered_at': null,
              'is_awaiting_delivery': true,
              'is_delivered': false,
            },
          ],
        ),
      );

      final member = tontine.members.single;
      expect(tontine.rotationRevealed, isTrue);
      expect(member.position, 3);
      expect(member.beneficiaryRound, 2);
      expect(member.status, TontineMemberStatus.beneficiary);
      expect(member.deliveryStatus, DeliveryStatus.awaitingPayment);
      expect(member.initials, 'AS');
    });

    test('une liste de membres absente ou malformée ne bloque pas la fiche', () {
      expect(parse(tontineJson(overrides: const {'members': null})).members, isEmpty);
      expect(parse(tontineJson(overrides: const {'members': 'oups'})).members, isEmpty);
    });
  });

  group('pagination', () {
    test('l\'enveloppe Laravel est lue data + meta', () {
      final page = PagedResult<Tontine>.fromLaravel(
        pagedJson([tontineJson(id: 1), tontineJson(id: 2)], lastPage: 4, total: 40),
        (json) => Tontine.fromJson(json),
      );

      expect(page.items.map((tontine) => tontine.id), [1, 2]);
      expect(page.currentPage, 1);
      expect(page.lastPage, 4);
      expect(page.total, 40);
      expect(page.hasMore, isTrue);
      expect(page.nextPage, 2);
    });

    test('la dernière page ne propose pas de suivante', () {
      final page = PagedResult<Tontine>.fromLaravel(
        pagedJson([tontineJson()], lastPage: 1, total: 1),
        (json) => Tontine.fromJson(json),
      );

      expect(page.hasMore, isFalse);
      expect(page.nextPage, isNull);
    });

    test('une réponse sans meta ne promet pas de page 2', () {
      // C'est le cas d'un appel à une ressource unique : annoncer une page
      // suivante qui n'existe pas ferait boucler « Charger plus ».
      final page = PagedResult<Tontine>.fromLaravel(tontineJson(), Tontine.fromJson);

      expect(page.items, isEmpty);
      expect(page.lastPage, 1);
      expect(page.hasMore, isFalse);
    });

    test('une enveloppe vide ou illisible ne plante pas', () {
      expect(PagedResult<Tontine>.fromLaravel(null, Tontine.fromJson).items, isEmpty);
      expect(
        PagedResult<Tontine>.fromLaravel({'data': 'oups'}, Tontine.fromJson).items,
        isEmpty,
      );

      final mixed = PagedResult<Tontine>.fromLaravel(
        {'data': ['pas un objet', tontineJson(id: 9)]},
        Tontine.fromJson,
      );
      expect(
        mixed.items.single.id,
        9,
        reason: 'un élément malformé est ignoré, les autres sont conservés',
      );
    });

    test('une pagination incohérente ne produit pas de page 0', () {
      final page = PagedResult<Tontine>.fromLaravel(
        {
          'data': [tontineJson()],
          'meta': {'current_page': 0, 'last_page': 0},
        },
        (json) => Tontine.fromJson(json),
      );

      // current_page 0 ferait boucler « page précédente » sans fin.
      expect(page.currentPage, 1);
      expect(page.lastPage, 1);
    });
  });
}
