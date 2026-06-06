# Prompt — implémentation mémoire conversationnelle (llm-basic)

Copier le bloc ci-dessous dans une **nouvelle conversation** Agent pour démarrer l’implémentation phase 1.

---

## Bloc à copier-coller

````markdown
## Contexte

Projet : **tivins/llm-basic** (PHP, lib LLM + Agent + tools + skills).

Une feuille de route est documentée dans :
`docs/conversational-memory-roadmap.md`

État actuel (v0.21.0) :
- `MessageStoreInterface` — persistance messages / mémoire / archives
- `MemoryCompactor` — compaction par seuil de caractères, fusion markdown via LLM
- `Agent` + `AgentHooks` — tours avec tools
- Pas encore : orchestration session, injection mémoire standard, `FileMessageStore`, exemple bout-en-bout

## Objectif de cette session

Implémenter la **phase 1** de la feuille de route :

1. **`FileMessageStore`** — implémentation de référence de `MessageStoreInterface` (fichiers : messages, memory.md, archives). Support d’un répertoire de session (et si simple : identifiant conversation optionnel).

2. **`ConversationBuilder`** (ou nom cohérent avec le code existant) — construire une `Conversation` à partir du store :
   - system prompt fourni par l’app
   - injection de `loadMemory()` dans le contexte (convention documentée : section markdown dans le system message, ex. `## Mémoire longue durée`)
   - rechargement des messages récents (`loadMessages()` → `Message` avec `meta`)

3. **`ConversationalSession`** (ou nom équivalent) — orchestration d’un tour utilisateur :
   - `runUserTurn(string $userContent, Agent $agent, ChatCompletionOptions $options): AgentTurnResult`
   - persiste user + assistant après succès
   - appelle `MemoryCompactor::compactIfNeeded()` après le tour
   - expose `contextProgress()` pour l’UI

4. **Tests** — smoke tests PHP dans `tests/` (sans appel LLM réel pour la compaction si possible ; mocker ou tester uniquement le wiring / FileMessageStore / builder).

5. **Exemple** — `examples/06_profile_conversation.php` (boucle stdin minimale : system prompt générique « découverte profil », store dans `tmp/`, session + agent).

6. **CHANGELOG + semver** — entrée sous `[Unreleased]` ou nouvelle version **minor** selon les conventions du repo ; mettre à jour `composer.json` version si le projet le fait systématiquement.

## Contraintes

- Réutiliser `MessageStoreInterface`, `MemoryCompactor`, `Agent`, `Message::withCreatedAt`, conventions existantes (`Role`, namespaces `Tivins\LlmBasic`).
- Pas de sur-ingénierie : pas de `RememberTool`, pas de `ProfileSummarizer`, pas de changement du prompt interne de `MemoryCompactor` dans cette phase (sauf extraction minimale si nécessaire pour tester).
- Code et commentaires en anglais dans `src/` ; doc utilisateur peut rester en français dans `docs/`.
- Tester les modifications (exécuter les tests ajoutés).
- Ne pas commit sauf si je le demande explicitement.

## Livrables attendus

- Liste des fichiers créés/modifiés
- Brève note sur la convention d’injection mémoire (où elle apparaît dans les messages)
- Commandes pour lancer tests + exemple

## Référence rapide

Lire avant de coder :
- `docs/conversational-memory-roadmap.md`
- `src/MemoryCompactor.php`
- `src/MessageStoreInterface.php`
- `tests/memory_compactor_test.php` (store in-memory de référence)
- `src/Agent.php`, `examples/04_writer.php` (patterns Agent / Skill)
````
