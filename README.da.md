# WordleX

[English](README.md) | **Dansk**

[wordlex.dk](https://wordlex.dk) er dansk Wordle med point, hints, Top-100 og en ordliste, som spillerne
selv er med til at bestemme over. Gratis, uden reklamer, og uden konto, hvis man bare vil spille.

## Spillet

- **To udgaver:** WordleX 5 ([w5.wordlex.dk](https://w5.wordlex.dk)) med 5 bogstaver og 6 forsøg, og
  WordleX 6 ([w6.wordlex.dk](https://w6.wordlex.dk)) med 6 bogstaver og 7 forsøg.
- **Point:** der gives point for hver ny grøn og gul brik, flere jo tidligere i spillet. En gul er halvt så
  meget værd som en grøn, og ordet i første forsøg giver 1.000 point. Pointene beholdes, også hvis ordet
  ikke gættes. Med en konto kommer de på leaderboardet og Top-100.
- **Hints:** et gratis forslag til startord i første række. Senere kan man købe listen over de ord, der stadig
  er mulige. Prisen afhænger af, hvor mange ord der er tilbage, og hvilken række man er i.

## Ordbogen

Spillerne bestemmer i fællesskab, hvilke ord der er med ([wordlex.dk/ord](https://wordlex.dk/ord/)).
Hvert ord er **almindeligt** (kan blive løsningsord), **sjældent** (godkendes som gæt, men er aldrig
løsningsord) eller **slettet** (godkendes ikke). Med en konto kan man ændre et ords status og foreslå nye
ord, altid med en begrundelse. Alle ændringer gemmes og kan ses af alle. Ord, som spillerne har prøvet at
gætte, men som ikke findes, vises som forslag.

## Teknik

PHP, MySQL og almindelig JavaScript, uden frameworks og uden build-trin. Spillene ligger på hver sit
subdomæne, men deler login, brugere og ordbog med wordlex.dk.

```
index.php, statistik.php   Forsiden og statistik
lib/                       Fælles konfiguration, login og API
w5/, w6/                   De to spil (index.html, api.php, stats.php)
ord/                       Ordbogen og admin
tools/                     Databasemigreringer og andre engangsscripts
```

## Opsætning

- `lib/config.php` vælger miljø (dev, test eller live) ud fra den mappe, koden ligger i, og bestemmer
  databaser, adresser og cookie-domæne. Tilpas stierne, hvis koden skal ligge et andet sted.
- Databasens brugernavn og adgangskode læses fra miljøvariablerne `WORDLEX_DB_USER` og
  `WORDLEX_DB_PASS`, fx sat med `SetEnv` i Apache eller i PHP-FPM-poolen. De står ikke i koden.
- Der bruges tre databaser: en fælles (brugere og ordbog) og én for hver udgave af spillet.
- I live-miljøet er `wordlex.dk` DocumentRoot for hele repoet, mens `w5.wordlex.dk` og `w6.wordlex.dk`
  peger på `w5/` og `w6/`. Login deles via cookie-domænet `.wordlex.dk`.

## Licens

MIT, se [LICENSE](LICENSE).
