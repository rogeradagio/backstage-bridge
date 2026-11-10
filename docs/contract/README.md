# Contracte Odoo → Magento

Font de veritat del format dels esdeveniments que `backstage` (Odoo) envia a `Backstage_Bridge` (Magento). Aquest contracte és l'única part compartida: l'especificació funcional de cada implantació viu al repositori del projecte que l'integra.

```
POST /V1/backstage/events
Authorization: Bearer <token d'integració amb Backstage_Bridge::events_write>
Content-Type: application/json
```

- Sobre: [event-envelope.schema.json](event-envelope.schema.json)
- Payloads: carpeta [payload/](payload/)

## Estat d'implementació

| `event_type` | Esquema | Magento |
|---|---|---|
| `preparing_at_warehouse` | [payload/preparing_at_warehouse.schema.json](payload/preparing_at_warehouse.schema.json) | Tirada 1: canvi de status |
| `in_transit_to_store` | [payload/in_transit_to_store.schema.json](payload/in_transit_to_store.schema.json) | Tirada 1: canvi de status |
| `ready_for_pickup` | [payload/ready_for_pickup.schema.json](payload/ready_for_pickup.schema.json) | Tirada 1: canvi de status |
| `collected` | [payload/shipment.schema.json](payload/shipment.schema.json) | Tirada 2: shipment des del punt de recollida; status `collected` quan tot està expedit, facturat, enllaçat i amb reserva neta zero |
| `shipment_dispatched` | [payload/shipment.schema.json](payload/shipment.schema.json) | Tirada 2: shipment des de la font; `dispatched` o `partially_fulfilled` |
| `invoice_issued` | [payload/invoice_issued.schema.json](payload/invoice_issued.schema.json) | Tirada 2: enllaç amb factura de captura (cas A) o factura mirall nova (cas B). Sempre `suppress` |
| `delivered` | [payload/delivered.schema.json](payload/delivered.schema.json) | Tirada 3: `delivered` només amb la comanda tancada; si no, 422 `completion_preconditions_failed` |
| `cancelled` | [payload/cancelled.schema.json](payload/cancelled.schema.json) | Tirada 3: cancel·lació nativa abans de cap shipment i sense factura viva (`has_shipments`, `invoice_requires_credit_note`) |
| `credit_note_issued` | [payload/credit_note_issued.schema.json](payload/credit_note_issued.schema.json) | Tirada 4: abonament mirall offline contra la factura enllaçada; mai retorna estoc. `online` només amb `allow_online_refund` (`online_refund_not_allowed`, `invoice_not_linked`) |
| `return_received` | [payload/return_received.schema.json](payload/return_received.schema.json) | Tirada 4: registra la devolució (tram `return`); no mou estoc ni diners |

Seqüència per anul·lar una comanda ja facturada i no expedida: `credit_note_issued` (total) → `cancelled`. Si l'abonament és total, Magento tanca la comanda (`closed`) i `cancelled` la dona per bona.

La resposta de `collected`, `shipment_dispatched` i `invoice_issued` inclou `pending_completion` amb el que encara falta per tancar la comanda: `shipment`, `invoice` o `reservation`.

## Correus al client

- Només s'envien amb `notification.mode = "send"`, quan l'esdeveniment acaba bé. Mai en `dry_run` ni en replay.
- `invoice_issued` no notifica mai (la factura fiscal la comunica Odoo).
- Un sol correu per `event_id` + plantilla: si Odoo reintenta, no es duplica.
- Plantilla per defecte segons l'esdeveniment; `notification.template` la pot canviar per un d'aquests codis: `preparing_at_warehouse`, `in_transit_to_store`, `ready_for_pickup`, `partially_fulfilled`, `dispatched`, `collected`, `delivered`, `cancelled`.
- `shipment_dispatched` fa servir `partially_fulfilled` o `dispatched` segons si queda res per enviar.
- Cada codi es mapeja a una plantilla per store view a *Stores → Configuration → Sales → Backstage Bridge → Correus al client*.
- Un correu fallit queda registrat (`backstage_bridge_notification`) però l'esdeveniment continua sent `processed`.

## Exemple

```json
{
  "event": {
    "event_id": "so-1001-ready",
    "event_type": "ready_for_pickup",
    "store_code": "es_es",
    "order_reference": "000000101",
    "occurred_at": "2026-11-07T10:20:00Z",
    "notification": { "mode": "suppress", "template": null, "comment": null },
    "payload": "{\"pickup_location_code\":\"store_01\"}"
  }
}
```

## Resposta 200

```json
{
  "event_id": "so-1001-ready",
  "status": "processed",
  "order_id": 123,
  "order_state": "processing",
  "order_status": "ready_for_pickup",
  "magento_invoice_ids": [],
  "magento_shipment_ids": [],
  "magento_creditmemo_ids": [],
  "pending_completion": [],
  "dry_run": false
}
```

`status`: `processed` | `replayed` (mateix `event_id` i mateix contingut: es retorna el resultat anterior) | `validated` (mode `dry_run`: no s'ha modificat res).

## Errors

El cos d'error és el de Magento REST. El codi de negoci va al principi de `message`, entre claudàtors:

```json
{ "message": "[status_not_allowed_for_state] Status \"collected\" is not allowed for state \"processing\" (allowed: complete)" }
```

Odoo ha de llegir el codi amb `^\[([a-z_]+)\]` i decidir pel codi HTTP: 4xx → `rejected` (no reintentar), 503 → reintentar.

| HTTP | `code` |
|---|---|
| 400 | `invalid_envelope`, `invalid_payload`, `unsupported_event_type` |
| 401/403 | (Magento) token o ACL |
| 404 | `order_not_found` |
| 409 | `event_id_conflict`, `event_in_progress` |
| 422 | `status_not_allowed_for_state`, `transition_not_allowed` i altres regles de negoci |
| 503 | `bridge_disabled`, `temporary_failure` (reintentable) |
