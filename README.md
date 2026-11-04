# WordleX

**English** | [Dansk](README.da.md)

[wordlex.dk](https://wordlex.dk) is a Danish Wordle-style word game with points, hints, a Top 100 and a word list
the players help decide. Free, without ads, and without an account if you just want to play.
The game and its user interface are in Danish.

## The game

- **Two versions:** WordleX 5 ([w5.wordlex.dk](https://w5.wordlex.dk)) with 5 letters and 6 guesses, and
  WordleX 6 ([w6.wordlex.dk](https://w6.wordlex.dk)) with 6 letters and 7 guesses.
- **Points:** you score for every new green and yellow tile, more the earlier in the game. A yellow is worth
  half a green, and guessing the word on the first try gives 1,000 points. You keep your points even if you
  don't guess the word. With an account they go on the leaderboard and the Top 100.
- **Hints:** a free starting word suggestion in the first row. Later you can buy the list of words that are
  still possible. The price depends on how many words are left and which row you are in.

## The dictionary

The players decide together which words are included ([wordlex.dk/ord](https://wordlex.dk/ord/)).
Each word is **common** (can be a solution), **rare** (accepted as a guess, but never a solution) or
**deleted** (not accepted). With an account you can change a word's status and suggest new words, always
with a reason. All changes are stored and visible to everyone. Words that players tried to guess but
that are not in the list are shown as suggestions.

## Tech

PHP, MySQL and plain JavaScript, no frameworks and no build step. Each game has its own subdomain,
but they share login, users and dictionary with wordlex.dk.

```
index.php, statistik.php   Front page and statistics
lib/                       Shared configuration, login and API
w5/, w6/                   The two games (index.html, api.php, stats.php)
ord/                       The dictionary and admin
tools/                     Database migrations and other one-off scripts
```

## Setup

- `lib/config.php` selects the environment (dev, test or live) based on the directory the code is in, and
  sets databases, URLs and cookie domain. Adjust the paths if the code lives elsewhere.
- The database username and password are read from the environment variables `WORDLEX_DB_USER` and
  `WORDLEX_DB_PASS`, e.g. set with `SetEnv` in Apache or in the PHP-FPM pool. They are not in the code.
- Three databases are used: a shared one (users and dictionary) and one for each version of the game.
- In the live environment, `wordlex.dk` uses the whole repository as DocumentRoot, while `w5.wordlex.dk` and
  `w6.wordlex.dk` point to `w5/` and `w6/`. Login is shared via the cookie domain `.wordlex.dk`.

## License

MIT, see [LICENSE](LICENSE).
