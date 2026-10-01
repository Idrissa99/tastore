import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/formatting/formatters.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/app_badge.dart';
import 'package:tontine_achat_store/core/widgets/avatar.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/core/widgets/state_views.dart';
import 'package:tontine_achat_store/features/profile/profile_repository.dart';

/// Profil de l'utilisateur connecté.
///
/// `PUT /profil` n'accepte que le nom, le téléphone et le mot de passe : l'e-mail,
/// le rôle et l'état de blocage y sont volontairement absents du formulaire,
/// plutôt que présentés et ignorés en silence.
class ProfilePage extends ConsumerStatefulWidget {
  const ProfilePage({super.key});

  @override
  ConsumerState<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends ConsumerState<ProfilePage> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _currentPassword = TextEditingController();
  final _newPassword = TextEditingController();

  bool _loaded = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _phone.dispose();
    _currentPassword.dispose();
    _newPassword.dispose();
    super.dispose();
  }

  void _prefill(ProfileView profile) {
    if (_loaded) return;

    _name.text = profile.name;
    _phone.text = profile.phone;
    _loaded = true;
  }

  Future<void> _save(ProfileView profile) async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    // Changer le mot de passe est une décision à part : on ne le déclenche
    // que si le champ a été rempli, et le mot de passe actuel est alors
    // exigé par le serveur (`required_with`).
    final changingPassword = _newPassword.text.isNotEmpty;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final user = await ref.read(profileRepositoryProvider).update(
            name: _name.text,
            phone: _phone.text,
            currentPassword: changingPassword ? _currentPassword.text : null,
            newPassword: changingPassword ? _newPassword.text : null,
          );

      // La session porte une copie de l'utilisateur : sans cette injection,
      // l'accueil continuerait d'afficher l'ancien nom.
      applyProfileToSession(ref, user);
      ref.invalidate(profileProvider);
      _currentPassword.clear();
      _newPassword.clear();

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            changingPassword
                ? 'Profil et mot de passe enregistrés.'
                : 'Profil enregistré.',
          ),
        ),
      );
    } on ApiException catch (error) {
      // « Le mot de passe actuel est incorrect », « ce téléphone est déjà
      // utilisé » : le serveur sait dire de quoi il s'agit.
      if (mounted) setState(() => _error = error.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(profileProvider);
    final auth = ref.watch(authControllerProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mon profil')),
      body: profile.when(
        loading: () => const LoadingView(label: 'Chargement du profil…'),
        error: (error, _) => ErrorView(
          message: '$error',
          onRetry: () => ref.invalidate(profileProvider),
        ),
        data: (raw) {
          final view = ProfileView.of(raw);
          _prefill(view);

          return Form(
            key: _formKey,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 32),
              children: [
                _Identity(profile: view),
                const SizedBox(height: 20),

                if (_error != null) ...[
                  ErrorBanner(message: _error!),
                  const SizedBox(height: 16),
                ],

                TextFormField(
                  controller: _name,
                  enabled: !_busy,
                  textCapitalization: TextCapitalization.words,
                  decoration: const InputDecoration(labelText: 'Nom complet'),
                  validator: (value) => (value ?? '').trim().isEmpty
                      ? 'Renseigne ton nom.'
                      : null,
                ),
                const SizedBox(height: 14),

                TextFormField(
                  controller: _phone,
                  enabled: !_busy,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Téléphone',
                    helperText: 'Sert à te joindre par la tontine.',
                  ),
                  validator: (value) => (value ?? '').trim().isEmpty
                      ? 'Renseigne ton numéro de téléphone.'
                      : null,
                ),
                const SizedBox(height: 14),

                // L'e-mail n'est pas modifiable : c'est dit explicitement plutôt
                // que présenté en lecture seule, qui ressemble à un oubli.
                TextFormField(
                  initialValue: view.email,
                  enabled: false,
                  decoration: const InputDecoration(
                    labelText: 'Adresse e-mail',
                    helperText: 'L\'adresse ne peut pas être modifiée ici.',
                  ),
                ),
                const SizedBox(height: 24),

                Text(
                  'Changer de mot de passe',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 4),
                const Text(
                  'Laisser les champs vides pour ne pas changer le mot de passe.',
                  style: TextStyle(fontSize: 12, color: AppColors.ink500),
                ),
                const SizedBox(height: 12),

                TextFormField(
                  controller: _currentPassword,
                  enabled: !_busy,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'Mot de passe actuel'),
                ),
                const SizedBox(height: 14),

                TextFormField(
                  controller: _newPassword,
                  enabled: !_busy,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'Nouveau mot de passe',
                    helperText: '8 caractères minimum.',
                  ),
                  validator: (value) {
                    final text = value ?? '';
                    if (text.isEmpty) return null;

                    return text.length < 8 ? '8 caractères minimum.' : null;
                  },
                ),
                const SizedBox(height: 24),

                FilledButton(
                  onPressed: _busy ? null : () => _save(view),
                  child: _busy
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.2,
                            valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                          ),
                        )
                      : const Text('Enregistrer'),
                ),

                const SizedBox(height: 16),
                Text(
                  auth.isEmailVerified
                      ? 'Compte créé le ${Fmt.date(view.createdAt)}.'
                      : 'Compte créé le ${Fmt.date(view.createdAt)} · e-mail non vérifié.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 11, color: AppColors.ink400),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Carte d'identité : avatar, nom, rôle et état de validation.
class _Identity extends StatelessWidget {
  const _Identity({required this.profile});

  final ProfileView profile;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                AppAvatar(
                  name: profile.name,
                  url: profile.raw['avatar_url']?.toString(),
                  size: 52,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        profile.name.isEmpty ? 'Profil' : profile.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                          color: AppColors.ink900,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        profile.email,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: AppColors.ink500, fontSize: 12),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                AppBadge(
                  label: switch (profile.role) {
                    'merchant' => 'Commerçant',
                    'admin' => 'Administrateur',
                    _ => 'Client',
                  },
                  tone: AppTone.neutral,
                ),
                AppBadge(
                  label: profile.isVerified ? 'E-mail vérifié' : 'E-mail non vérifié',
                  tone: profile.isVerified ? AppTone.success : AppTone.warning,
                  icon: profile.isVerified ? Icons.verified_outlined : Icons.warning_amber_outlined,
                ),
                if (profile.merchantStatus.isNotEmpty)
                  AppBadge(label: 'Vendeur : ${profile.merchantStatus}', tone: AppTone.brand),
              ],
            ),
            if (profile.businessName.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                profile.businessName,
                style: const TextStyle(fontSize: 12, color: AppColors.ink500),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
