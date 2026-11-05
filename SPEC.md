# **Zadanie Rekrutacyjne: Payment Webhook Ingestion & Notification Pipeline**

## **Kontekst Biznesowy**

Budujemy mikro-system dla platformy e-commerce, który odbiera webhooki o zdarzeniach płatności od dostawcy (np. Stripe/PayU), przetwarza je i wysyła asynchroniczne powiadomienie do zespołu sprzedaży.

Klient przesłał mocno skomplikowane wizje (chce m.in. kolejkę Message Broker, powiadomienia na 5 kanałów i raporty AI), a system ma działać na dzisiaj, być odporny na duplikaty zdarzeń (idempotentność) oraz nieprzerywalny w przypadku chwilowych awarii zewnętrznych API. 

## **Zadanie**

### **Komunikacja z Klientem**

Napisz wiadomość e-mail do klienta i zapisz jej treść w pliku `EMAIL_TO_CLIENT.md`. Weź pod uwagę sytuację biznesową, nierealne oczekiwania klienta oraz fakt, że przejmujesz ten projekt – napisz maila, którego wysłałbyś do niego przed rozpoczęciem prac.

### **Architektura**

Przeanalizuj scenariusze odbiegające od ścieżki podstawowej (*happy path*) i zabezpiecz je w koncepcji architektonicznej. Uwzględnij m.in.:

**Race Condition / Concurrent Requests:** Wpadnięcie dwóch identycznych webhooków (`event_id`) w dokładnie tej samej milisekundzie.  
**Out-of-order Delivery:** Sytuację, w której zdarzenie `charge.successful` trafia na serwer szybciej niż utrwalenie samej intencji płatności w bazie danych.  
**External API Failures & Backoff:** Chwilowy brak dostępności zewnętrznego serwisu powiadomień (HTTP 5xx / Timeout) i strategię ponawiania prób (*Exponential Backoff \+ Dead Letter Queue/Storage*).  
**Poison Pills / Malformed Payloads:** Obsługę uszkodzonych lub niekompletnych żądań HTTP przy jednoczesnym utrzymaniu czytelnego logowania błędów.

### 

### **Vibecoding**

Stwórz działający system w języku **PHP** (wybierz dowolny framework, np. Laravel, Symfony, Slim, lub czysty PHP z Composerem) przy użyciu narzędzi AI (Cursor, Claude, Copilot):

**Endpoint API:** `POST /api/v1/webhooks/payment` odbierający produkcyjny payload od bramki płatniczej:

JSON

```
{
  "event": "charge.successful",
  "event_id": "evt_9f8d7c6b5a4321",
  "timestamp": 1718029200,
  "livemode": true,
  "data": {
    "transaction": {
      "id": "txn_8a7b6c5d4e3f",
      "provider_reference": "PAYU-9876543210",
      "amount": {
        "value": 29990,
        "currency": "PLN"
      },
      "payment_method": {
        "type": "blik",
        "issuer": "mBank"
      },
      "status": "COMPLETED"
    },
    "customer": {
      "id": "cus_11223344",
      "email": "jan.kowalski@example.com",
      "billing_address": {
        "first_name": "Jan",
        "last_name": "Kowalski",
        "company": "Kowalski Dev Ltd",
        "country": "PL"
      }
    },
    "metadata": {
      "order_id": "ORD-2024-8839",
      "store_id": "store_main_pl",
      "channel": "mobile_app"
    }
  }
}
```

**Dispatcher Powiadomień:** Asynchroniczny proces/worker, który bierze zdarzenie i wysyła powiadomienie do zewnętrznego serwisu (zrób mocka zewnętrznego API).

### **Audyt Kodu**

Zweryfikuj wygenerowany kod pod kątem wyścigów (race condition – co jeśli 2 takie same webhooki wpadną w tej samej milisekundzie?).  
Zabezpiecz endpoint (np. weryfikacja nagłówka / token / sygnatura).  
Oczyść kod z jakichkolwiek zbędnych klas, przerośniętych abstrakcji czy śmieciowych plików wygenerowanych przez AI.

### **Wdrożenie i Konteneryzacja**

Przygotuj plik `docker-compose.yml`, który odpala całe środowisko (PHP / serwer www / baza danych lub storage / worker).

System musi wystartować i być gotowy do przetestowania po uruchomieniu jednej komendy: `docker compose up`.

**Udostępnienie dla zespołu Strix**

**Opcja A** 

Prześlij spakowany kod aplikacji na adres [przemyslaw.szarlej@strix.net](mailto:przemyslaw.szarlej@strix.net)

**Opcja B**

Umieść rozwiązanie w publicznym repozytorium (GitHub / GitLab) lub udostępnij gotowe obrazy w rejestrze (np. Docker Hub / GitHub Packages).  
Repozytorium musi zawierać instrukcję krok po kroku w pliku `README.md` umożliwiającą szybkie pobranie, uruchomienie oraz wysłanie testowego zapytania `curl`.

