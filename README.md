# Backstage Bridge

Mòdul Magento 2 que rep esdeveniments de compliment procedents d'un ERP i els aplica amb les APIs natives de Magento: shipments, factures, abonaments, estat de comanda i notificacions.

## Instal·lació per Composer

El paquet no és a Packagist: declara el repositori al `composer.json` del projecte Magento.

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/rogeradagio/backstage-bridge.git" }
]
```

```bash
composer require backstage/module-bridge
bin/magento setup:upgrade
```

El mòdul es registra com `Backstage_Bridge`. L'endpoint és `POST /V1/backstage/events`; el contracte es troba a [docs/contract](docs/contract).

## Documentació de l'API (Swagger)

L'especificació OpenAPI és a [docs/openapi.yaml](docs/openapi.yaml), amb exemples de petició per a cada `event_type`, les respostes i els codis d'error. Per veure-la amb Swagger UI cal servir la carpeta `docs/` per HTTP, perquè l'especificació referencia els esquemes de `docs/contract/payload`:

```bash
npx http-server docs -p 8080
# obre http://localhost:8080/swagger.html
```

L'endpoint també apareix al Swagger natiu de Magento (`https://<botiga>/swagger`), però allà `payload` només surt com a string, sense el detall per tipus d'esdeveniment.

Per desenvolupar-lo localment, declara aquest repositori com a `path` repository al projecte Magento i instal·la'l amb Composer. Les proves d'integració requereixen una instal·lació Magento amb MSI.

## Llicència

MIT. Copyright (c) 2026 Roger Pedrol. Vegeu [LICENSE](LICENSE).
