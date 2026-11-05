# Email do klienta

> Szkic — do przeglądu przed wysyłką. Treść merytoryczna (zakres, decyzje architektoniczne) jest zgodna z `AGENTS.md`. Wersja PL (formalna, "Pan") i EN poniżej.

---

## PL

**Temat:** Przejęcie projektu „Payment Webhook Ingestion" — warunki techniczne i zakres pierwszego etapu

Szanowny Panie,

Przejmuję prowadzenie projektu integracji webhooków płatności. Zanim zacznę pracę nad kodem, chciałbym przedstawić Panu warunki techniczne, na jakich mogę odpowiedzialnie zbudować tę pierwszą wersję, oraz zaproponować podział pozostałego zakresu na etapy.

**Warunki techniczne pierwszej wersji — niepodlegające negocjacji:**

System przetwarza dane płatności, więc poniższe elementy traktuję jako warunek, a nie opcję do rozważenia:

- **Kolejka / message broker.** To realna ochrona przed utratą zdarzeń przy chwilowej niedostępności serwisu powiadomień (automatyczne ponawianie z rosnącym odstępem czasu, plus bezpieczne miejsce na zdarzenia, którym się to nie udało). Bez tego nie mogę zagwarantować, że żadne powiadomienie o płatności nie zginie po drodze.
- **Idempotentność i podpisany endpoint.** Ten sam `event_id` trafiający w tym samym momencie nie może utworzyć dwóch rekordów, a każde żądanie musi być zweryfikowane podpisem. Przy danych płatności to minimum, nie rozszerzenie.
- **Czysta obsługa błędnych danych.** Niekompletny lub uszkodzony payload nie może wywrócić serwisu — ma skończyć się czytelnym logiem i jasną odpowiedzią błędu, zawsze.

Przyjmuję to jako podstawę pracy; jeśli z jakiegoś powodu nie są Państwu potrzebne (np. środowisko testowe o innej charakterystyce), proszę o sygnał przed startem — w przeciwnym razie zakładam, że powyższe wchodzi do pierwszej wersji.

**Zakres do wspólnego ustalenia:**

1. **Powiadomienia na wielu kanałach.** Proponuję zacząć od jednego kanału (na razie zasymulowanego), zaprojektowanego tak, by dodanie kolejnych (np. Slack, SMS, drugi e-mail) nie wymagało przebudowy systemu. Każdy kolejny kanał to jednak odrębna integracja i własne testy — chciałbym je traktować jako osobne, policzalne zadania, a nie "włącznik" w już działającym systemie.
2. **Raporty z wykorzystaniem AI.** Proponuję wyłączyć je z tej iteracji — to odrębny temat produktowy (jakie dane, w jakiej formie, dla kogo, jak często), który zasługuje na osobną rozmowę o wymaganiach, zanim zaczniemy go kodować.

**Co otrzyma Pan po pierwszym etapie:**
- endpoint przyjmujący webhooki, odporny na duplikaty i nieprawidłowe dane,
- asynchroniczny mechanizm wysyłki powiadomień do jednego kanału, z ponownymi próbami i zabezpieczeniem przed utratą zdarzeń,
- całość uruchamianą jedną komendą, z instrukcją obsługi.

Proszę o potwierdzenie, że powyższe warunki techniczne są dla Pana zrozumiałe i akceptowalne, oraz o informację, jak chciałby Pan rozłożyć priorytety w punktach do ustalenia — zaczynam pracę od razu po potwierdzeniu.

Z poważaniem,
[Imię i nazwisko]

---

## EN

**Subject:** Taking over "Payment Webhook Ingestion" — technical conditions and scope for phase one

Hello,

I'm taking over the payment webhook integration project. Before I start writing code, I'd like to set out the technical conditions under which I can responsibly build this first version, and propose how to split the remaining scope into phases.

**Technical conditions for phase one — not up for negotiation:**

This system processes payment data, so I'm treating the following as a requirement rather than a discussion point:

- **A message queue.** This is real protection against losing events if the notification service is briefly unavailable (automatic retries with increasing delay, plus a safe place for anything that still fails). Without it, I can't guarantee that a payment notification won't simply disappear.
- **Idempotency and a signed endpoint.** The same `event_id` arriving at the same instant must not create two records, and every request must be signature-verified. For payment data, this is the baseline, not an add-on.
- **Clean handling of bad input.** A malformed or incomplete payload must never take the service down — it has to end in a readable log entry and a clear error response, every time.

I'm treating this as the foundation of the work. If for some reason these aren't needed in your context (e.g. a test environment with different constraints), please flag it before I start — otherwise I'll assume all of the above is part of phase one.

**Scope to align on together:**

1. **Notifications across multiple channels.** I'd suggest starting with one channel (mocked for now), built behind an interface so adding more later (Slack, SMS, a second email channel) doesn't require rebuilding the system. Each additional channel is still its own integration and its own tests, though — I'd like to treat those as separate, scoped work rather than a "flip a switch" feature on top of what's already running.
2. **AI-generated reports.** I'd suggest leaving these out of this iteration — that's a separate product question (what data, what format, for whom, how often) that deserves its own requirements conversation before any code gets written.

**What you'll have after phase one:**
- An endpoint that accepts webhooks and is resilient to duplicates and bad input,
- An asynchronous worker that delivers notifications to one channel, with retries and a safety net for failures,
- Everything runnable with a single command, with setup instructions included.

Could you confirm that the technical conditions above are clear and acceptable, and let me know how you'd like to prioritize the points still open for discussion? I'll get started as soon as I have your confirmation.

Best regards,
[Your name]
