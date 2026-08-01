# Season Builder guidance

These rules extend the repository-root `AGENTS.md` for everything under `season-builder/`.

## Architecture and state

- `season_setup.php` owns Basics and Round Builder structure.
- `season_options.php` configures the saved round structure and controls the explicit transition that opens voting.
- `ML_SeasonRoundSlots` stores editable builder slots. `ML_SeasonRounds` stores committed gameplay rounds. They are not interchangeable.
- Never delete and recreate `ML_SeasonRoundSlots` during Step 1 saves. Upsert slots by `(SeasonID, RoundNumber)` so stable IDs and child data survive.
- `ML_SeasonRoundOptionChoices` depends on season round slots. A cascade delete of a slot can erase configured Option Vote choices.
- Preserve fixed-round, User Submitted Round, Madlibs, and Option Vote behavior when editing shared builder helpers.
- If a schema-dependent feature is unavailable, keep the existing readiness checks and give the admin a useful message rather than failing mid-save.

## Voting lifecycle safety

- Saving setup or options must not open league voting unless the admin explicitly chose the Start Voting action.
- Preview Voting must be admin-only, reuse the actual player voting UI, and remain read-only: no vote rows, submissions, completion state, notifications, or `voting_open` changes.
- Preview forms must not have a fallback path to `submit.php`; server-side behavior must remain safe even without JavaScript.
- Player vote submission belongs in `submit.php`. Keep related deletes/inserts inside one transaction and preserve resubmission behavior.
- Do not mark a user submitted until all vote data has been written successfully.
- Treat Discord notifications and season-state transitions as side effects that require explicit review when the voting lifecycle changes.

## Player-facing UI

- Season Builder voting should feel like the main gameplay ballot, not a separate mini-app. Reuse the hierarchy, ballot cards, progress language, controls, spacing, and action patterns from `vote.php` and shared styles.
- The actual question is the main page heading. Season/player/admin context is secondary.
- User Submitted Round plus/minus controls must use valid theme-appropriate icons and remain visible in light and dark mode.
- Selected, disabled, hover, and focus states must remain clear for User Submitted Rounds, Madlibs, and Option Votes.
- Keep point totals and selection limits synchronized between PHP validation, JavaScript behavior, labels, and progress indicators.
- Test the full wizard at common desktop and narrow-phone widths. Long titles and descriptions must not detach from their controls or cause horizontal overflow.

## Focused verification

- Lint every changed Season Builder PHP file with `php -l`.
- Run `node --check season-builder/questions.js` whenever its behavior changes.
- Confirm the following paths conceptually or manually as applicable: first visit, saved/resumed vote, validation failure, back/next navigation, final submit, admin preview, and voting closed.
- For changes to `season_setup.php`, verify repeated saves retain existing Option Vote choices.
- For changes to `season_options.php`, verify ordinary Save and Preview leave `voting_open` unchanged; only Start Voting may set it.
- For CSS or icon changes, verify both themes and at least one narrow phone width.
