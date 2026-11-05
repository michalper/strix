# Email do klienta

> Szkic — do przeglądu i ewentualnej korekty tonu przed wysyłką. Treść merytoryczna (zakres, decyzje architektoniczne) jest zgodna z `AGENTS.md`.

---

**Temat:** Przejęcie projektu „Payment Webhook Ingestion" — zakres na start i kilka pytań

Dzień dobry,

Przejmuję projekt integracji webhooków płatności i powiadomień dla zespołu sprzedaży. Zanim zacznę pisać kod, chciałbym ustawić z Tobą zakres na pierwszą wersję — tak, żeby to, co dostaniesz **dzisiaj**, faktycznie działało i było czymś, na czym można bezpiecznie budować dalej, a nie obietnicą na później.

Z briefu wynika kilka elementów, które warto rozbić na etapy:

**1. Kolejka / message broker — zostaje.**
To nie jest "nice to have", a realna ochrona przed utratą zdarzeń przy chwilowej awarii serwisu powiadomień (retry z exponential backoff + dead letter po wyczerpaniu prób). Wchodzi do pierwszej wersji.

**2. Powiadomienia na 5 kanałów — nie na start.**
Na pierwszą wersję robimy **jeden kanał** (mockowane HTTP API), ale zaprojektowany tak, że dodanie kolejnego kanału (np. Slack, SMS, drugi e-mail) to kwestia nowej implementacji jednego interfejsu, a nie przebudowy systemu. Każdy kolejny kanał to jednak dodatkowa integracja, config i testy — realnie policzmy je jako osobne zadania, nie jako "włącznik" w systemie, który już działa.

**3. Raporty AI — poza zakresem tej iteracji.**
To osobny temat produktowy (jakie dane, jaki format, dla kogo, jak często) i wymaga odrębnej rozmowy o wymaganiach, zanim zacznie się to kodować. Nie chcę zgadywać zakresu i wrzucać czegoś, co trzeba będzie potem przepisać.

**4. Bezpieczeństwo i odporność na błędy — traktuję jako wymóg, nie opcję.**
Endpoint będzie zabezpieczony podpisem (HMAC), zduplikowane zdarzenia (ten sam `event_id` trafiający w tej samej milisekundzie) będą obsłużone tak, żeby zawsze powstał tylko jeden rekord, a błędne/niekompletne payloady nie wywalą serwisu — dostaniesz czytelny log i odpowiedź 4xx, nie 500.

**Co dostajesz na "dzisiaj":**
- Endpoint przyjmujący webhook, odporny na duplikaty i błędne dane,
- Asynchroniczny worker wysyłający powiadomienie do jednego (mockowanego) kanału z retry i dead-letterem,
- Całość odpalana jedną komendą (`docker compose up`), z instrukcją w `README.md`.

Jeśli ten zakres Ci odpowiada, zaczynam od razu. Jeśli chcesz inaczej rozłożyć priorytety (np. kanały ważniejsze niż raporty, albo odwrotnie) — daj znać przed tym, jak zacznę, żeby nie przerabiać architektury w połowie.

Pozdrawiam,
[Twoje imię]
