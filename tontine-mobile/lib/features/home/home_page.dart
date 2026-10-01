import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/config/app_config.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/features/notifications/notification_providers.dart';
import 'package:tontine_achat_store/features/profile/email_verification_banner.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Onglet « Accueil ».
///
/// Il sert deux choses : prouver que la session EST réelle — le nom affiché
/// vient de `GET /me`, donc d'une réponse du serveur, et non d'un jeton
/// simplement présent dans le keystore — et donner accès direct aux deux
/// sections qui comptent : catalogue et cotisations.
class HomePage extends ConsumerWidget {
  const HomePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final user = auth.user;
    final name = user?['name']?.toString() ?? '';
    final email = user?['email']?.toString() ?? '';
    final merchant = user?['merchant'];
    final merchantStatus = merchant is Map ? merchant['status']?.toString() : null;
    final unread = ref.watch(unreadNotificationsProvider).valueOrNull ?? 0;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Tontine Achat Store'),
        actions: [
          IconButton(
            tooltip: 'Mon profil',
            icon: const Icon(Icons.person_outline),
            onPressed: () => context.push(AppRoutes.profile),
          ),
          IconButton(
            tooltip: 'Se déconnecter',
            icon: const Icon(Icons.logout),
            onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    name.isEmpty ? 'Session ouverte' : 'Bonjour $name',
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.w800,
                      color: AppColors.ink900,
                    ),
                  ),
                  if (email.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      email,
                      style: const TextStyle(color: AppColors.ink500, fontSize: 13),
                    ),
                  ],
                  const SizedBox(height: 14),
                  _RoleChip(role: user?['role']?.toString()),
                ],
              ),
            ),
          ),

          // Le serveur refuse tout versement sur un compte non vérifié
          // (middleware `verified`) : le dire ici évite que l'utilisateur ne
          // découvre le blocage au moment de payer.
          if (!auth.isEmailVerified) ...[
            const SizedBox(height: 20),
            const EmailVerificationBanner(),
          ],

          // L'inscription avec le rôle « commerçant » crée un profil vendeur
          // `pending` côté serveur, et rien d'autre que l'administrateur ne
          // peut le passer à `approved`. Sans cet avertissement, un espace
          // vendeur vide donnerait l'impression que la demande est perdue.
          if (merchantStatus != null && merchantStatus != 'approved') ...[
            const SizedBox(height: 20),
            const Card(
              child: Padding(
                padding: EdgeInsets.all(20),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      Icons.hourglass_top_outlined,
                      size: 20,
                      color: AppColors.accent600,
                    ),
                    SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Espace commerçant en attente de validation. '
                        'Un administrateur doit approuver ton compte avant que '
                        'tu puisses vendre tes produits.',
                        style: TextStyle(color: AppColors.ink600, fontSize: 13, height: 1.4),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],

          const SizedBox(height: 20),
          _Shortcut(
            icon: Icons.savings_outlined,
            title: 'Découvrir les tontines',
            subtitle: 'Trouve une épargne qui te convient et rejoins-la.',
            onTap: () => context.go(AppRoutes.catalog),
          ),
          const SizedBox(height: 12),
          _Shortcut(
            icon: Icons.groups_outlined,
            title: 'Mes tontines',
            subtitle: 'Les tontines que tu as rejointes et ta place dans chacune.',
            onTap: () => context.push(AppRoutes.myTontines),
          ),
          const SizedBox(height: 12),
          _Shortcut(
            icon: Icons.receipt_long_outlined,
            title: 'Mes cotisations',
            subtitle: 'Verse tes cotisations et suis ce qui reste à payer.',
            onTap: () => context.go(AppRoutes.contributions),
          ),

          const SizedBox(height: 12),
          _Shortcut(
            icon: Icons.shopping_bag_outlined,
            title: 'Acheter un produit',
            subtitle: 'Paye en plusieurs tranches, sans emprunt.',
            onTap: () => context.push(AppRoutes.products),
          ),
          const SizedBox(height: 12),
          _Shortcut(
            icon: Icons.inventory_2_outlined,
            title: 'Mes achats',
            subtitle: 'Suis et solde les tranches de tes achats.',
            onTap: () => context.push(AppRoutes.purchases),
          ),

          // Espace commerçant : proposé UNIQUEMENT à un vendeur approuvé.
          //
          // L'avertissement plus haut couvre le cas « en attente de
          // validation », et ce raccourci le cas « approuvé ». Les deux ne
          // peuvent pas coexister : afficher un accès à la vente à un compte
          // qui n'en a pas encore le droit l'enverrait sur un écran bloqué.
          if (user?['role']?.toString() == 'merchant' && merchantStatus == 'approved') ...[
            _Shortcut(
              icon: Icons.storefront_outlined,
              title: 'Mon espace commerçant',
              subtitle: 'Produits, chiffre d\'affaires et livraisons à confirmer.',
              onTap: () => context.push(AppRoutes.merchant),
            ),
            const SizedBox(height: 12),
          ],

          // Le raccourci reste TOUJOURS présent, pastille ou non : il était
          // masqué tant qu'il n'y avait rien à lire, donc lire ses
          // notifications faisait disparaître le seul chemin vers cet écran —
          // et les messages suivants n'étaient plus consultables que par un
          // push, ou jamais.
          _Shortcut(
            icon: unread > 0 ? Icons.notifications_active_outlined : Icons.notifications_none,
            title: 'Notifications',
            subtitle: unread == 0
                ? 'Rien de nouveau depuis ta dernière visite.'
                : unread == 1
                    ? '1 message non lu.'
                    : '$unread messages non lus.',
            badge: unread > 0 ? unread : null,
            onTap: () => context.push(AppRoutes.notifications),
          ),

          const SizedBox(height: 20),
          Card(
            child: ListTile(
              leading: const Icon(Icons.dns_outlined, size: 20),
              title: const Text(
                'Backend visé',
                style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
              ),
              subtitle: Text(
                AppConfig.apiBaseUrl,
                style: const TextStyle(color: AppColors.ink500, fontSize: 12),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Carte d'accès à une section.
class _Shortcut extends StatelessWidget {
  const _Shortcut({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
    this.badge,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final int? badge;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(AppRadius.lg),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Row(
            children: [
              Icon(icon, size: 22, color: AppColors.primary600),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        color: AppColors.ink900,
                        fontSize: 15,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      subtitle,
                      style: const TextStyle(color: AppColors.ink500, fontSize: 13),
                    ),
                  ],
                ),
              ),
              if (badge != null)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: AppColors.danger500,
                    borderRadius: BorderRadius.circular(AppRadius.xs),
                  ),
                  child: Text(
                    '$badge',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ),
              const Icon(Icons.chevron_right, size: 20, color: AppColors.ink400),
            ],
          ),
        ),
      ),
    );
  }
}

class _RoleChip extends StatelessWidget {
  const _RoleChip({this.role});

  final String? role;

  @override
  Widget build(BuildContext context) {
    final label = switch (role) {
      'merchant' => 'Commerçant',
      'admin' => 'Administrateur',
      'client' || null => 'Client',
      _ => role!,
    };

    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: AppColors.primary50,
          borderRadius: BorderRadius.circular(AppRadius.xs),
        ),
        child: Text(
          label,
          style: const TextStyle(
            color: AppColors.primary700,
            fontSize: 12,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}
