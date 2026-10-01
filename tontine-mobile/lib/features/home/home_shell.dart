import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/sync/live_sync.dart';

/// Coquille à onglets de l'application connectée.
///
/// Elle ne porte que deux choses : la pile des onglets et la barre de
/// navigation. Le contenu de chaque onglet est une branche du routeur, ce qui
/// laisse `go_router` conserver la position de défilement et l'historique de
/// chaque section — reconstruire une seule pile à chaque changement d'onglet
/// ferait perdre le fil de la navigation.
///
/// Les fiches, elles, sont des routes à part entière, hors de la coque : on lit
/// une tontine ou un achat en plein écran, sans barre d'onglets, et on revient
/// exactement d'où l'on venait.
///
/// C'est aussi ici qu'on resynchronise au changement d'onglet : chaque pile est
/// conservée, donc un onglet quitté peut afficher un état devenu faux — un
/// code de virement validé depuis le web, une tranche livrée. Le re-tap sur
/// l'onglet courant passe par là aussi : il remonte la section à jour.
class HomeShell extends ConsumerWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      body: shell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        // `goBranch(i)` gère aussi le re-tap sur l'onglet courant : il ramène
        // en haut de la section au lieu de ne rien faire, ce qui est le
        // comportement attendu sur un téléphone.
        onDestinationSelected: (index) {
          ref.read(liveSyncProvider).sync();
          shell.goBranch(index);
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Accueil',
          ),
          NavigationDestination(
            icon: Icon(Icons.savings_outlined),
            selectedIcon: Icon(Icons.savings),
            label: 'Tontines',
          ),
          NavigationDestination(
            icon: Icon(Icons.receipt_long_outlined),
            selectedIcon: Icon(Icons.receipt_long),
            label: 'Cotisations',
          ),
        ],
      ),
    );
  }
}
