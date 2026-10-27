# GDPR, délivrabilité & métriques — la couche non négociable

## GDPR : marketing vs transactionnel
- **Transactionnel** (reçus, reset mot de passe, mises à jour sécurité/licence, infos de service) : base = exécution du contrat (Art. 6(1)(b)). Pas de consentement marketing. Ne doit pas être un marketing déguisé.
- **Marketing** : base légale propre requise.
  - B2C UE = **consentement** (libre, spécifique, éclairé, granulaire, horodaté, révocable).
  - **Soft opt-in** (intérêt légitime, Art. 6(1)(f)) : défendable seulement pour un **client existant**, produits **similaires** à un achat antérieur, opt-out à chaque envoi, balancing test documenté.
- **Cas du plugin gratuit** : un téléchargeur gratuit n'est pas un « client ayant acheté » → soft opt-in fragile. Voie sûre = **case opt-in marketing séparée, non pré-cochée, granulaire** (produit vs newsletter), jamais groupée avec les CGU.

## Double opt-in
- **Obligatoire** : Allemagne, Autriche, Suisse, Grèce, Luxembourg, Norvège.
- Ailleurs : best practice pour **prouver** le consentement (charge de la preuve GDPR) + meilleure qualité/délivrabilité. Recommandé par défaut pour une audience UE.

## Exigences Gmail / Yahoo / Microsoft
- Seuil bulk : **> 5 000 messages/jour** vers un fournisseur (Gmail/Yahoo depuis fév. 2024 ; Microsoft depuis mai 2025).
- **SPF + DKIM + DMARC** alignés (DMARC ≥ `p=none`). Recommander dès le 1er envoi (le seuil se franchit vite ; sans auth même un petit volume va en spam).
- **One-click unsubscribe** (RFC 8058, header `List-Unsubscribe-Post`) obligatoire depuis juin 2024 ; traiter sous 2 jours.
- **Taux de plainte spam < 0,3 %** = ligne rouge d'enforcement ; **viser < 0,1 %**.
- Enforcement durci : 421 (deferral) 2024 → **rejets 550** depuis nov. 2025. La non-conformité **bloque**, ne dégrade plus.

## Métriques post-MPP (Apple Mail Privacy Protection)
- MPP (2021) pré-charge les pixels → **machine opens** : Apple Mail ~50-58 % des opens, open rate gonflé de +15 à +35 pts. **Inutilisable en absolu.**
- Les **clics bruts** sont aussi corrompus (pré-scan sécurité = faux clics bots).
- **Mesurer à la place** :
  1. **Revenue-per-email + taux de conversion** (la seule vraie question).
  2. **Conversions / actions produit** (upgrade, feature activée, checkout, visites issues de l'email).
  3. **Reply rate** (KPI montant ; même ~1 % = signal de confiance pour les providers).
  4. **CTOR hors Apple Mail / clics uniques** filtrés des bots.
- L'open rate ne sert qu'au **timing relatif** et à l'A/B de sujet sur un même segment.

## Mythes email réfutés (ne pas recommander)
- « Open rate = KPI clé » → cassé par MPP.
- « Envoyer plus = plus de revenu » → 81 % se désabonnent pour volume excessif ; fatigue → réputation → spam. Segmentation + centre de préférences.
- « Listes achetées OK » → illégal UE, CAN-SPAM (pénalité civile par email en infraction, montant révisé chaque année par la FTC), réputation détruite.
- « Pas besoin d'auth en petit volume » → vrai sur le papier, mauvais conseil opérationnel (spam + Microsoft 550).
- « Sujets putaclic / faux Re: » → illégal CAN-SPAM, spam.
- « Mardi 10h universel » → moyenne d'industrie ; tester par audience (B2B matin, B2C souvent soir).
- « no-reply@ » → bloque les réponses, baisse l'engagement (signal négatif), onglet Promotions.
- « Emails tout-image jolis » → spam + aveugle en dark mode ; viser ~60/40 texte/image + alt-text.

## Sources
GDPR : igdpr · GDPRWise · TermsFeed · emailtooltester/Suped/Mailjet (double opt-in). Délivrabilité : Gmail FAQ · dmarcian · Red Sift · Mailgun · Security Boulevard · Microsoft sender requirements. MPP/métriques : datainnovation · beehiiv · Twilio · Litmus · Geysera · Hustler Marketing. Fatigue/volume : Mailmend · Omnisend. Sujets trompeurs : Litmus · Validity.
