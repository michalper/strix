# Email do klienta

> Szkic — do przeglądu przed wysyłką. Treść merytoryczna (zakres, decyzje architektoniczne) jest zgodna z `AGENTS.md`. Język celowo nietechniczny — klient nie jest osobą techniczną. Wersja PL (formalna, "Pan") i EN poniżej.

---

## PL

**Temat:** Przejęcie projektu „Payment Webhook Ingestion" — warunki techniczne i zakres pierwszego etapu

Szanowny Panie,

Przejmuję projekt, który odbiera zgłoszenia o płatnościach i przekazuje powiadomienia do zespołu sprzedaży. Zanim zacznę pracę, chciałbym przedstawić Panu kilka warunków, bez których nie mogę wziąć za ten system odpowiedzialności. Chciałbym też zaproponować, która część prac wykonamy w pierwszej kolejności, a która na dalszych etapach.

**Warunki niezbędne do rozpoczęcia pracy:**

System rejestruje płatności klientów, dlatego poniższe punkty traktuję jako warunek konieczny realizacji projektu:

- **Każde zgłoszenie o płatności musi dotrzeć do celu.** Jeśli nasz system powiadomień będzie przez chwilę niedostępny (np. przeciążony lub w trakcie awarii), zgłoszenie nie przepadnie — system będzie próbował je dostarczyć ponownie, z coraz dłuższą przerwą między próbami, a jeśli to się mimo wszystko nie uda, zgłoszenie trafi do bezpiecznego miejsca, skąd można je później ręcznie obsłużyć.
- **Jedna płatność nie może zostać zarejestrowana dwa razy.** Jeśli dostawca płatności wyśle to samo zgłoszenie dwukrotnie (co się zdarza, np. przy chwilowych problemach z łącznością), system musi to rozpoznać i zapisać tylko jeden raz.
- **System musi sam rozpoznawać, że zgłoszenie rzeczywiście pochodzi od dostawcy płatności, a nie od kogoś, kto się pod niego podszywa.** To podstawowa ochrona przed fałszywymi zgłoszeniami.
- **Niekompletne lub uszkodzone dane nie mogą spowodować awarii systemu.** Jeśli coś przyjdzie w nieprawidłowej formie, system to odnotuje w zrozumiały sposób i da znać, że coś jest nie tak — ale nie przestanie działać.

Przyjmuję powyższe jako fundament pracy. Jeśli z jakiegoś powodu nie są Panu potrzebne w tej formie, proszę o informację przed rozpoczęciem prac. W przeciwnym razie zakładam, że wchodzą do pierwszej wersji.

**Co proponuję odłożyć na później, do wspólnego ustalenia:**

1. **Powiadomienia na wielu kanałach (np. SMS, Slack, druga skrzynka e-mail).** W pierwszym etapie wdrożymy jeden kanał powiadomień, zaprojektowany tak, aby dodanie kolejnych kanałów w przyszłości nie wymagało ponownej budowy całego systemu. Każdy dodatkowy kanał to osobna integracja i własne testy, dlatego proponuję traktować je jako odrębne zadania, wycenione indywidualnie.
2. **Raporty generowane przez AI.** Proponuję wyłączyć ten element z obecnej iteracji. Wymaga odrębnej rozmowy o zakresie (jakie dane powinny się w nich znaleźć, w jakiej formie, dla kogo i z jaką częstotliwością), zanim przystąpimy do jego realizacji. Chciałbym oprzeć się na precyzyjnie określonym zakresie, aby uniknąć późniejszych poprawek wynikających z błędnych założeń.

**Co otrzyma Pan po pierwszym etapie:**
- punkt przyjmujący zgłoszenia o płatnościach, odporny na duplikaty i błędne dane,
- mechanizm wysyłki powiadomień do jednego kanału, z automatycznym ponawianiem i zabezpieczeniem przed utratą zgłoszeń,
- całość uruchamianą jedną komendą, z instrukcją obsługi.

Proszę o potwierdzenie, że powyższe warunki są dla Pana akceptowalne, oraz o informację, co jest dla Pana ważniejsze z punktów do ustalenia. Zaczynam pracę od razu po potwierdzeniu.

Z poważaniem,
[Imię i nazwisko]

---

## EN

**Subject:** Taking over "Payment Webhook Ingestion" — technical conditions and scope for phase one

Hello,

I'm taking over the project that receives payment notifications and forwards them to the sales team. Before I start, I'd like to set out a few conditions I need in place to responsibly take ownership of this system. I'd also like to propose which parts of the work we tackle first, and which we leave for later phases.

**Conditions I need in place before I start:**

This system registers customers' payments, so I'm treating the following as a technical condition for delivering the project:

- **No payment notification can ever go missing.** If our notification service is briefly unavailable (overloaded, or down for maintenance), the notification won't be lost — the system will keep retrying, waiting a bit longer each time, and if it still doesn't get through, it gets placed somewhere safe so it can be handled by hand later.
- **The same payment can never be recorded twice.** If the payment provider sends the same notification more than once (which does happen, e.g. during brief connectivity issues), the system needs to recognize that and only record it once.
- **The system must be able to tell that a notification genuinely came from the payment provider, not from someone pretending to be them.** That's basic protection against fake notifications.
- **Incomplete or corrupted data must never bring the system down.** If something arrives in the wrong shape, the system logs it clearly and flags that something's wrong, but keeps running.

I'm treating the above as the foundation of the work. If for some reason you don't need these in this form, please let me know before I start — otherwise I'll assume they're part of the first version.

**What I'd suggest leaving for later, to agree on together:**

1. **Notifications across multiple channels (e.g. SMS, Slack, a second email inbox).** In phase one, we will implement a single notification channel, designed so that adding further channels later does not require rebuilding the system. Each additional channel is a separate integration with its own testing, so I would suggest treating each as an individually scoped and estimated task.
2. **AI-generated reports.** I would suggest excluding this item from the current iteration — it requires a dedicated requirements discussion (what data should be included, in what format, for whom, and how often) before implementation begins. I would prefer to base this work on a precisely defined scope, in order to avoid later revisions resulting from incorrect assumptions.

**What you'll have after phase one:**
- A point that accepts payment notifications and is resilient to duplicates and bad data,
- A mechanism that delivers notifications to one channel, with automatic retries and protection against lost notifications,
- Everything runnable with a single command, with setup instructions included.

Could you confirm the conditions above are acceptable, and let me know which of the open points matters more to you? I'll get started as soon as I have your confirmation.

Best regards,
[Your name]
