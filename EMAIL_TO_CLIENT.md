# Email do klienta

> Szkic — do przeglądu przed wysyłką. Treść merytoryczna (zakres, decyzje architektoniczne) jest zgodna z `AGENTS.md`. Język celowo nietechniczny — klient nie jest osobą techniczną. Wersja PL (formalna, "Pan") i EN poniżej.

---

## PL

**Temat:** Przejęcie projektu „Payment Webhook Ingestion" — warunki techniczne i zakres pierwszego etapu

Szanowny Panie,

Przejmuję prowadzenie projektu odbierania zgłoszeń o płatnościach. Zanim zacznę pracę, chciałbym przedstawić Panu kilka warunków, bez których nie mogę wziąć za ten system odpowiedzialności. Chciałbym też zaproponować, która część prac wykonamy w pierwszej kolejności, a która na dalszych etapach.

**Warunki niezbędne do rozpoczęcia pracy:**

Ten system rejestruje płatności klientów, dlatego poniższe punkty traktuję jako warunek techniczny realizacji projektu:

- **Żadne zgłoszenie o płatności nie może się zgubić.** Jeśli nasz system powiadomień będzie przez chwilę niedostępny (np. przeciążony lub w trakcie awarii), zgłoszenie nie przepadnie — system będzie próbował je dostarczyć ponownie, z coraz dłuższą przerwą między próbami, a jeśli to się mimo wszystko nie uda, zgłoszenie trafi do bezpiecznego miejsca, skąd można je później ręcznie obsłużyć.
- **Jedna płatność nie może zostać zarejestrowana dwa razy.** Jeśli dostawca płatności wyśle to samo zgłoszenie dwukrotnie (co się zdarza, np. przy chwilowych problemach z łącznością), system musi to rozpoznać i zapisać tylko jeden raz.
- **System musi sam rozpoznawać, że zgłoszenie rzeczywiście pochodzi od dostawcy płatności, a nie od kogoś, kto się pod niego podszywa.** To podstawowa ochrona przed fałszywymi zgłoszeniami.
- **Niekompletne lub uszkodzone dane nie mogą wywrócić systemu.** Jeśli coś przyjdzie w nieprawidłowej formie, system to odnotuje w zrozumiały sposób i da znać, że coś jest nie tak — ale nie przestanie działać.

Przyjmuję powyższe jako fundament pracy. Jeśli z jakiegoś powodu nie są Panu potrzebne w tej formie, proszę o informację przed rozpoczęciem prac — w przeciwnym razie zakładam, że wchodzą do pierwszej wersji.

**Co proponuję odłożyć na później, do wspólnego ustalenia:**

1. **Powiadomienia na wielu kanałach (np. SMS, Slack, druga skrzynka e-mail).** W pierwszym etapie zrobimy jeden kanał powiadomień, ale tak, żeby dodanie kolejnych w przyszłości nie wymagało przebudowy całego systemu od zera. Każdy kolejny kanał to jednak dodatkowa praca i testy, więc proponuję traktować je jako osobne, wycenione zadania, a nie coś, co "po prostu się włącza".
2. **Raporty generowane przez AI.** Proponuję wyłączyć je z tej iteracji — to osobny temat (jakie dane mają się w nich znaleźć, w jakiej formie, dla kogo, jak często), który zasługuje na odrębną rozmowę, zanim zaczniemy to budować. Wolałbym nie zgadywać, czego Panu potrzeba, i nie robić czegoś, co trzeba będzie później przerabiać.

**Co otrzyma Pan po pierwszym etapie:**
- punkt przyjmujący zgłoszenia o płatnościach, odporny na duplikaty i błędne dane,
- mechanizm wysyłki powiadomień do jednego kanału, z automatycznym ponawianiem i zabezpieczeniem przed utratą zgłoszeń,
- całość uruchamianą jedną komendą, z instrukcją obsługi.

Proszę o potwierdzenie, że powyższe warunki są dla Pana zrozumiałe i akceptowalne, oraz o informację, co jest dla Pana ważniejsze z punktów do ustalenia — zaczynam pracę od razu po potwierdzeniu.

Z poważaniem,
[Imię i nazwisko]

---

## EN

**Subject:** Taking over "Payment Webhook Ingestion" — technical conditions and scope for phase one

Hello,

I'm taking over the project that receives payment notifications and forwards them to the sales team. Before I start, I'd like to set out a few conditions I need in place to responsibly take ownership of this system, and propose what we build now versus what we leave for later.

**Conditions I need in place before I start:**

This system handles customers' money, so I'm treating the following as requirements rather than discussion points:

- **No payment notification can ever go missing.** If our notification service is briefly unavailable (overloaded, or down for maintenance), the notification won't be lost — the system will keep retrying, waiting a bit longer each time, and if it still doesn't get through, it gets placed somewhere safe so it can be handled by hand later.
- **The same payment can never be recorded twice.** If the payment provider sends the same notification more than once (which does happen, e.g. during brief connectivity issues), the system needs to recognize that and only record it once.
- **The system must be able to tell that a notification genuinely came from the payment provider, not from someone pretending to be them.** That's basic protection against fake notifications.
- **Incomplete or corrupted data must never bring the system down.** If something arrives in the wrong shape, the system logs it clearly and flags that something's wrong, but keeps running.

I'm treating the above as the foundation of the work. If for some reason you don't need these in this form, please let me know before I start — otherwise I'll assume they're part of the first version.

**What I'd suggest leaving for later, to agree on together:**

1. **Notifications across multiple channels (e.g. SMS, Slack, a second email inbox).** For phase one, we'll build one notification channel, but in a way that adding more later doesn't mean rebuilding the whole system from scratch. Each additional channel is still extra work and extra testing, though, so I'd suggest treating each one as its own task to scope and estimate, rather than something that "just gets switched on."
2. **AI-generated reports.** I'd suggest leaving these out of this round — that's a separate topic (what should be in them, in what format, for whom, how often) that deserves its own conversation before we start building it. I'd rather not guess at what you need and end up building something that has to be redone.

**What you'll have after phase one:**
- A point that accepts payment notifications and is resilient to duplicates and bad data,
- A mechanism that delivers notifications to one channel, with automatic retries and protection against lost notifications,
- Everything runnable with a single command, with setup instructions included.

Could you confirm the conditions above are clear and acceptable, and let me know which of the open points matters more to you? I'll get started as soon as I have your confirmation.

Best regards,
[Your name]
