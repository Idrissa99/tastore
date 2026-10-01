import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:tontine_achat_store/core/auth/auth_controller.dart';
import 'package:tontine_achat_store/core/network/api_exception.dart';
import 'package:tontine_achat_store/core/theme/app_theme.dart';
import 'package:tontine_achat_store/core/widgets/error_banner.dart';
import 'package:tontine_achat_store/features/auth/auth_scaffold.dart';
import 'package:tontine_achat_store/routing/app_router.dart';

/// Création de compte.
///
/// `POST /register` attend six champs (`RegisterRequest`), repris ici à
/// l'identique du formulaire web : nom, e-mail, téléphone, mot de passe, sa
/// confirmation, et le RÔLE. Ce dernier est le seul qui ne soit pas une simple
/// saisie — il décide si le compte disposera d'un espace vendeur, et le backend
/// ne l'accepte qu'à l'inscription, jamais après (`ProfileController::update`
/// l'ignore explicitement). Il est donc demandé ici, une fois pour toutes.
///
/// Les règles locales ne font que REFLETER celles du serveur : sur un réseau
/// lent, refuser un mot de passe de 7 caractères évite un aller-retour, et
/// l'écran reste utilisable hors connexion pour la saisie. Elles ne remplacent
/// pas la validation du backend, qui reste seule juge — un e-mail déjà pris
/// ne peut s'apprendre que de lui.
class RegisterPage extends ConsumerStatefulWidget {
  const RegisterPage({super.key});

  @override
  ConsumerState<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends ConsumerState<RegisterPage> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();

  /// `client` par défaut : c'est le cas majoritaire, et un compte commerçant
  /// provisions un profil vendeur en attente de validation, qu'il faut aller
  /// chercher chez un administrateur. `admin` n'est pas proposé :
  /// `RegisterRequest` refuse tout rôle hors `client,merchant`.
  String _role = 'client';

  bool _busy = false;
  String? _error;

  /// Erreurs 422 du backend, indexées par nom de champ Laravel. Elles
  /// s'affichent sous le champ concerné ; le bandeau global ne sert que
  /// lorsque le refus ne vise aucun champ identifiable.
  final Map<String, String> _fieldErrors = {};

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    _confirmation.dispose();
    super.dispose();
  }

  /// Oublie l'erreur que le serveur avait collée à un champ dès que
  /// l'utilisateur le corrige : la laisser affichée sous une saisie valide
  /// donnerait l'impression que la correction n'a pas été prise en compte.
  void _clearFieldError(String field) {
    if (_fieldErrors.remove(field) == null) return;
    setState(() {});
  }

  Future<void> _submit() async {
    setState(() {
      _fieldErrors.clear();
      _error = null;
    });

    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() => _busy = true);

    try {
      await ref.read(authControllerProvider.notifier).register(
            name: _name.text,
            email: _email.text,
            phone: _phone.text,
            password: _password.text,
            passwordConfirmation: _confirmation.text,
            role: _role,
          );
      // Pas de navigation manuelle : le passage à `authenticated` suffit, le
      // routeur redirige. C'est lui l'unique décideur — même règle que sur
      // l'écran de connexion.
    } on ApiException catch (error) {
      if (!mounted) return;

      final perField = <String, String>{};
      error.validationErrors.forEach((field, messages) {
        if (messages.isNotEmpty) perField[field] = messages.first;
      });

      setState(() {
        _fieldErrors.addAll(perField);
        // Un 422 dont aucun champ ne correspond à un champ connu laisserait
        // l'écran muet : on retombe alors sur le message global.
        _error = perField.isEmpty ? error.message : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AuthScaffold(
      title: 'Créer mon compte',
      subtitle: 'Rejoins une tontine en une minute. Ton inscription est gratuite.',
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_error != null) ...[
              ErrorBanner(message: _error!),
              const SizedBox(height: 20),
            ],

            TextFormField(
              controller: _name,
              enabled: !_busy,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.name],
              onChanged: (_) => _clearFieldError('name'),
              decoration: InputDecoration(
                labelText: 'Nom complet',
                prefixIcon: const Icon(Icons.person_outline, size: 20),
                errorText: _fieldErrors['name'],
              ),
              validator: _required('Renseigne ton nom complet.'),
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _email,
              enabled: !_busy,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.email],
              onChanged: (_) => _clearFieldError('email'),
              decoration: InputDecoration(
                labelText: 'Adresse e-mail',
                prefixIcon: const Icon(Icons.mail_outline, size: 20),
                errorText: _fieldErrors['email'],
              ),
              validator: _emailValidator,
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _phone,
              enabled: !_busy,
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.telephoneNumber],
              onChanged: (_) => _clearFieldError('phone'),
              decoration: InputDecoration(
                labelText: 'Téléphone',
                hintText: '+227 90 00 00 00',
                prefixIcon: const Icon(Icons.phone_outlined, size: 20),
                helperText: 'Sert à te contacter en cas de besoin.',
                errorText: _fieldErrors['phone'],
              ),
              validator: _phoneValidator,
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _password,
              enabled: !_busy,
              obscureText: true,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.newPassword],
              onChanged: (_) => _clearFieldError('password'),
              decoration: InputDecoration(
                labelText: 'Mot de passe',
                prefixIcon: const Icon(Icons.lock_outline, size: 20),
                errorText: _fieldErrors['password'],
              ),
              validator: _passwordValidator,
            ),
            const SizedBox(height: 16),

            TextFormField(
              controller: _confirmation,
              enabled: !_busy,
              obscureText: true,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.newPassword],
              onChanged: (_) => _clearFieldError('password_confirmation'),
              decoration: InputDecoration(
                labelText: 'Confirmer le mot de passe',
                prefixIcon: const Icon(Icons.lock_outline, size: 20),
                errorText: _fieldErrors['password_confirmation'],
              ),
              validator: _confirmationValidator,
            ),
            const SizedBox(height: 24),

            _RolePicker(
              value: _role,
              enabled: !_busy,
              onChanged: (role) => setState(() => _role = role),
            ),
            // `RegisterRequest` n'accepte que `client` ou `merchant` : la
            // sélection ci-dessus rend cette erreur de serveur impossible en
            // l'état. Le bandeau reste nevertheless câblé, pour le jour où le
            // backend élargirait la liste sans que l'app le sache.
            if (_fieldErrors['role'] != null)
              Padding(
                padding: const EdgeInsets.only(top: 8, left: 2),
                child: Text(
                  _fieldErrors['role']!,
                  style: const TextStyle(
                    color: AppColors.danger600,
                    fontSize: 12,
                  ),
                ),
              ),
            const SizedBox(height: 24),

            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                        strokeWidth: 2.2,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    )
                  : const Text('Créer mon compte'),
            ),
            const SizedBox(height: 16),

            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Text(
                  'Déjà un compte ?',
                  style: TextStyle(color: AppColors.ink500, fontSize: 13),
                ),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () => context.go(AppRoutes.login),
                  child: const Text('Se connecter'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  /* ------------------------------------------------------------------ */
  /* Validateurs                                                        */
  /* ------------------------------------------------------------------ */

  static FormFieldValidator<String> _required(String message) =>
      (value) => (value ?? '').trim().isEmpty ? message : null;

  static String? _emailValidator(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Renseigne ton adresse e-mail.';
    if (!text.contains('@') || !text.contains('.')) {
      return 'Cette adresse ne semble pas valide.';
    }
    return null;
  }

  /// 20 caractères, la limite de `RegisterRequest`. Le contrôle ne cherche pas
  /// à valider un format international — les numéros écrits avec des espaces
  /// (« +227 90 00 00 00 ») sont acceptés par le backend, ils doivent l'être
  /// ici aussi.
  static String? _phoneValidator(String? value) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return 'Renseigne ton numéro de téléphone.';
    if (text.length > 20) return 'Ce numéro est trop long (20 caractères maximum).';
    return null;
  }

  /// `Password::min(8)` côté serveur. Le message ne vit QUE dans le
  /// validateur : en `helperText`, il resterait affiché pendant que le même
  /// texte s'affiche en rouge en dessous, une fois l'erreur levée.
  static String? _passwordValidator(String? value) {
    final text = value ?? '';
    if (text.isEmpty) return 'Choisis un mot de passe.';
    if (text.length < 8) return '8 caractères minimum.';
    return null;
  }

  /// Instance, et non fonction statique : la confirmation ne peut être jugée
  /// que par rapport au mot de passe saisi, que le validateur ne reçoit pas.
  /// Le contrôle a lieu à la validation du formulaire, donc sur des valeurs à
  /// jour.
  String? _confirmationValidator(String? value) {
    final text = value ?? '';
    if (text.isEmpty) return 'Confirme ton mot de passe.';
    if (text != _password.text) {
      return 'La confirmation ne correspond pas au mot de passe.';
    }
    return null;
  }
}

/// Choix du profil : deux cartes, comme les `RadioCard` du SPA.
class _RolePicker extends StatelessWidget {
  const _RolePicker({
    required this.value,
    required this.onChanged,
    this.enabled = true,
  });

  final String value;
  final ValueChanged<String> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Je m\'inscris en tant que',
          style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 10),
        // `IntrinsicHeight` donne au `Row` une hauteur bornée, sans quoi
        // `CrossAxisAlignment.stretch` — seul moyen d'aligner le bas des deux
        // cartes — n'aurait rien à étirer dans une colonne de défilement.
        IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Expanded(
                child: _RoleCard(
                  selected: value == 'client',
                  enabled: enabled,
                  icon: Icons.person_outline,
                  title: 'Client',
                  description: 'Je veux rejoindre des tontines',
                  onTap: () => onChanged('client'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _RoleCard(
                  selected: value == 'merchant',
                  enabled: enabled,
                  icon: Icons.storefront_outlined,
                  title: 'Commerçant',
                  description: 'Je veux vendre mes produits',
                  onTap: () => onChanged('merchant'),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        const Text(
          'Un compte commerçant est validé par un administrateur avant l\'accès '
          'à l\'espace vendeur.',
          style: TextStyle(color: AppColors.ink400, fontSize: 11, height: 1.4),
        ),
      ],
    );
  }
}

class _RoleCard extends StatelessWidget {
  const _RoleCard({
    required this.selected,
    required this.enabled,
    required this.icon,
    required this.title,
    required this.description,
    required this.onTap,
  });

  final bool selected;
  final bool enabled;
  final IconData icon;
  final String title;
  final String description;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    // La bordure vit sur le `Material` et non sur un `Container` superposé :
    // c'est la seule façon pour que l'encre du `InkWell` soit découpée au
    // rayon du cadre au lieu de déborder sur les coins.
    return Material(
      color: selected ? AppColors.primary50 : Colors.white,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.md),
        side: BorderSide(
          color: selected ? AppColors.primary600 : AppColors.ink200,
          width: selected ? 1.6 : 1,
        ),
      ),
      child: InkWell(
        onTap: enabled ? onTap : null,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                icon,
                size: 20,
                color: selected ? AppColors.primary700 : AppColors.ink500,
              ),
              const SizedBox(height: 8),
              Text(
                title,
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 2),
              Text(
                description,
                style: const TextStyle(color: AppColors.ink500, fontSize: 11, height: 1.3),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
