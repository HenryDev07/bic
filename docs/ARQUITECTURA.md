# BIC — Propuesta técnica (v2, aprobada con ajustes)

Prioridades, en este orden: **integridad de datos → precisión financiera → seguridad → escalabilidad → velocidad de entrega**.
Arquitectura completa conservada; **cada etapa crea solo sus tablas, con sus pruebas, y no avanza hasta que pasan**.

## 1. Decisiones vigentes

| Tema | Decisión |
|---|---|
| Estilo | Monolito modular MVC + servicios + repositorios; módulos registrables por `module.php` |
| Dependencias | **Composer desde el inicio**: autoload PSR-4 (`Bic\` → `src/`), PHPUnit 11. Sin `bcmath`: `Decimal`/`Money` (`src/Core/Money`) usan enteros escalados con redondeo half-up y detectan desbordamientos |
| Negocio | Uno activo; `business_id` en toda tabla de negocio (el linter lo exige) |
| Moneda | USD base. Documentos en otra moneda guardan `currency` + `exchange_rate DECIMAL(18,8)` por operación |
| Dinero / cantidades | `DECIMAL(14,2)` importes · `(14,4)` costos unitarios · `(14,3)` cantidades · `(7,4)` tasas. Nunca FLOAT (el linter lo bloquea) |
| Fechas | UTC en base de datos; zona horaria del negocio al mostrar |
| Esquema | **Las migraciones son la única fuente**. `schema.sql` se genera de ellas y `composer test` falla si no coincide. Migración aplicada = inmutable (checksum) |
| Acceso a datos | La app usa `bic_app` (solo SELECT/INSERT/UPDATE/DELETE por tabla). `root` solo en CLI y no se guarda. `audit_logs` y `stock_movements` son **solo SELECT+INSERT** a nivel de MySQL |
| IA | Después del motor analítico; proveedor intercambiable; sin SQL libre |

## 2. Ciclo de vida comercial: pedido → venta

Una sola entidad `orders`, pero **solo es "venta" (ingreso) cuando está entregada**.

| Estado | Stock reservado | Stock físico | Ingreso reconocido | Cobros |
|---|---|---|---|---|
| `draft` | no | no | no | no |
| `pending` | opcional¹ | no | no | anticipo permitido |
| `confirmed` | **sí** (por defecto)¹ | no | no | anticipo permitido |
| `prepared` | sí | no | no | anticipo permitido |
| `delivered` | la reserva se **consume** | **sale** (movimiento definitivo) | **sí, en `delivered_at`** | cobro / saldo |
| `cancelled` | se **libera** | no cambia | no | anticipos → reembolso obligatorio |

¹ Configurable: `order_reserve_stock_on = pending | confirmed | never`. La salida física siempre ocurre al entregar.

Reglas:
* Un pedido `delivered` **no se cancela**: se revierte con una **devolución** (`order_returns`), que repone stock (a su costo original) solo si el artículo es revendible.
* Ventas, ganancia bruta y ticket promedio = pedidos `delivered` por `delivered_at`, menos devoluciones. Los pedidos abiertos aparecen aparte como **cartera de pedidos**.
* El costo de cada línea se congela al entregar (`unit_cost_snapshot`), de modo que el CMV no cambia si cambian costos después.
* Transiciones válidas solo hacia adelante o a `cancelled` (salvo `delivered`); toda transición queda en `order_status_history`.
* Una venta de mostrador pasa `pending → delivered` en una sola transacción.
* Anti-duplicado: token en `idempotency_keys` con índice único.

## 3. Inventario: físico, reservado, disponible

* `stock_levels` (por variante y almacén): `quantity` = **físico**, `reserved_quantity` = **reservado**, `available_quantity` = físico − reservado (columna generada), `avg_cost`.
* `stock_reservations` (por línea de pedido): estado `active | released | consumed`. Confirmar reserva, cancelar libera, entregar consume. Invariante verificada por prueba: `reserved_quantity = SUM(reservas activas)`.
* El físico **solo** cambia con `stock_movements` (inmutable, cantidad con signo, saldo posterior, referencia al documento). Las correcciones son movimientos inversos.
* Concurrencia: transacción + `SELECT … FOR UPDATE` sobre `stock_levels`, siempre en orden de `variant_id` (evita bloqueos mutuos).
* No se reserva más que el disponible, salvo configuración explícita. Stock físico negativo prohibido por defecto.
* Valoración: costo promedio ponderado; capas FIFO preparadas, activables con fecha de corte (nunca mezcladas).

## 4. Finanzas sin duplicación

Libro de caja único `financial_transactions` (entrada/salida por cuenta, con `voided_at` para anular, nunca borrar).
**Cada documento que mueve dinero guarda `financial_transaction_id` con FK y UNIQUE** → relación 1:1 garantizada por la base, sin referencias polimórficas:

| Documento | Mueve caja | Afecta resultados (P&L) |
|---|---|---|
| `order_payments` (cobro, anticipo, reembolso) | sí | no por sí solo; el ingreso se reconoce al entregar. Anticipos = pasivo (dinero de clientes) |
| `purchase_payments` | sí | **no**: compra = inventario (activo) |
| `expenses` | sí | sí (gasto operativo) |
| `capital_movements` | sí | no |
| `account_transfers` | dos filas (salida + entrada) | no |

* Fletes, aranceles e impuestos no recuperables de una compra van en `purchase_cost_lines` (se capitalizan al costo de lo recibido) y **no** como gasto: un mismo costo no puede estar en ambos lados. Los gastos pueden ligarse a pedido, producto o proveedor, no a compras.
* Diferencia cambiaria: el costo de inventario usa el tipo de cambio del documento; el pago registra el suyo y la diferencia se reporta aparte.
* Pruebas de conciliación por etapa: toda fila-origen tiene exactamente un asiento; saldo de cuenta = saldo inicial + asientos vigentes; ningún asiento sin origen salvo ingresos manuales etiquetados.

## 5. Precios e impuestos

* `tax_rates` (nombre, tasa, activa) — **no hay tasa universal**. Producto: `tax_rate_id` y `price_includes_tax` opcionales; si faltan, aplica el valor por defecto del negocio. Cada línea de pedido/compra guarda su snapshot (`tax_rate`, `price_includes_tax`, `net_unit_price`, `tax_amount`) y puede sobrescribirlo. Exento = tasa 0 explícita.
* Precio con impuesto incluido: `neto = precio / (1 + t)`. Redondeo half-up a 2 decimales **por línea**; el total es la suma de líneas redondeadas.
* Compras: cada línea marca si el impuesto es recuperable; el no recuperable se capitaliza al costo.

Fórmulas (todas visibles en la interfaz). `C` = costo unitario puesto en almacén + costos variables fijos por unidad; `v` = costos variables porcentuales sobre el precio neto (comisión, pasarela); `m` = margen objetivo sobre el precio neto; `F` = costos fijos del período:

| Concepto | Fórmula |
|---|---|
| **Precio de equilibrio** (ganancia = 0) | `C / (1 − v)` |
| **Precio recomendado** (margen objetivo) | `C / (1 − v − m)`, exige `v + m < 1` |
| Margen sobre ventas | `(P − costos) / P` |
| Markup (sobre costo) | `(P − C) / C` |
| Punto de equilibrio en unidades | `F / (P·(1 − v) − C)` |
| ROI | `ganancia / inversión` |

Son cálculos independientes: el de equilibrio nunca incluye margen; el recomendado siempre parte de uno explícito.

## 6. Seguridad

Contraseñas `password_hash`; sesión `HttpOnly`/`SameSite`, regenera ID al entrar; límite de intentos (`login_attempts`); CSRF en toda escritura; escape por defecto en vistas; PDO preparado en el 100 %; permisos validados en servidor (`Gate::can('sales.cancel')`); auditoría en `audit_logs`; archivos fuera de `public/`. Claves de IA solo en configuración del servidor. Privilegios mínimos de base de datos (§1) con verificación automática en `bin/db-check.php`.

## 7. Plan por etapas (cada una: migraciones + pruebas + commit)

| Etapa | Contenido | Criterio de aceptación |
|---|---|---|
| **2 — Base** *(hecha)* | Composer, PDO, migrador, `schema.sql` sincronizado, linter, usuario `bic_app`; tablas: `businesses`, `warehouses`, `settings`, `users`, `roles`, `permissions`, `role_permissions`, `user_roles`, `login_attempts`, `audit_logs`, `idempotency_keys` | `composer test` verde; `install.php` + `db-check.php` correctos en tu WAMP |
| **3 — Estructura** *(hecha)* | Router, layout y sidebar morado, login con bloqueo, usuarios, roles con anti-escalada, configuración, auditoría, CSRF, CSP estricta, dashboard base con datos reales | Sin permiso → 403 en servidor (probado con peticiones completas) |
| **4a — Catálogo y proveedores** *(hecha)* | `tax_rates`, unidades, categorías, marcas, productos, variantes, historial de precios/costos, proveedores y cotizaciones (comparación por costo total puesto en almacén) | CRUD + historial + CSV; el vendedor ve precios pero no costos |
| **4b — Núcleo financiero** *(hecha)* | `financial_accounts`, `financial_transactions`, `capital_movements`, `account_transfers`, `Decimal`/`Money`, calculadoras de impuesto y precio | Conciliación de saldos; sin saldos negativos; 1 documento ↔ 1 asiento |
| **4c — Inventario** *(hecha)* | `stock_levels`, `stock_movements` (inmutable), ajustes, transferencias, kardex, conciliación. `stock_reservations` llega con los pedidos (4e) y las capas FIFO quedan preparadas | Saldo = suma de movimientos; sin stock negativo ni sacar lo reservado |
| **4d — Compras** *(hecha)* | órdenes, líneas con impuesto recuperable o no, costos de llegada, recepciones parciales, pagos ligados al libro de caja | Costo promedio correcto al recibir; compra ≠ gasto |
| **4e — Clientes y pedidos** *(hecha)* | `customers`, `orders`, `order_lines`, `stock_reservations`, `order_status_history`, `order_payments`, `order_returns(+lines)`; reservas, entrega con costo congelado, anticipos/cobros/reembolsos ligados al libro, devoluciones proporcionales, idempotencia de cobros | Ciclo de §2 completo, sin doble registro |
| 4f — Gastos e investigación | `expenses`, `research_*` | Ganancia bruta/neta cuadran con casos a mano |
| 4g — Dashboard real | Indicadores y gráficos | Ninguna cifra estática |
| 5 — Inteligencia y reportes | Clasificación, ABC, rotación, equilibrio, PDF/Excel/CSV | Reportes = dashboard |
| 6 — IA | Proveedores intercambiables, contexto, recomendaciones | Funciona con y sin API |
| 7 — Cierre | Pruebas globales, optimización, manuales | Lista de la sección 21 del prompt en verde |

Funcionalidades evaluadas y su momento: notificaciones y adjuntos (con el primer módulo que los use), copias de seguridad (etapa 7), promociones complejas, garantías y calendario (posponer), facturación fiscal electrónica (fuera de alcance).

## 8. Notas de las etapas 4a/4b
* **Cantidades y dinero en PHP**: nunca `float`. `Decimal::from()` acepta valores de SQLite/MySQL y los normaliza; `Decimal::fromInput()` interpreta lo que escribe una persona (`1,234.50`, `12,5`).
* **Impuestos**: tasa en porcentaje; `Exento` (0 %) es la única tasa inicial. Redondeo por línea (`TaxCalculator`).
* **Precios mínimos** (equilibrio/recomendado) se redondean hacia arriba al centavo.
* **Permisos nuevos**: `products.view/cost/create/edit`, `catalog.view/manage`, `suppliers.view/create/edit`, `finance.view/create/void/manage`. `products.cost` (costos, márgenes, cotizaciones) no lo tiene el vendedor.
* **Base de datos**: `financial_transactions` admite SELECT/INSERT/UPDATE (se anula, no se borra); `product_price_history` y `supplier_prices` son solo SELECT/INSERT (verificado por `db-check.php`).
* **Libro de caja**: el saldo no se almacena; `FinanceService::post()` es el único punto que inserta asientos y lo reutilizarán cobros, pagos y gastos.
* `source_type` es texto validado por la aplicación para que las etapas siguientes añadan orígenes sin alterar la tabla.

## 9. Notas de las etapas 4c/4d
* **Disponible** = físico − reservado, calculado en las consultas (no hay columna que pueda desincronizarse).
* `InventoryService::move()` es el único punto que cambia el físico: bloquea la fila, valida, actualiza el saldo y escribe el movimiento en la misma transacción. Salidas al costo promedio; entradas recalculan el promedio ponderado.
* **Costo puesto en almacén** (por unidad, moneda base) = precio × tipo de cambio de la orden + impuesto no recuperable + costos de llegada repartidos por valor de línea (`costos × valor_unitario / valor_total_orden`). Los costos de llegada se bloquean tras la primera recepción para que el reparto sea coherente.
* **Pagos**: se hacen en moneda base contra el total de la orden convertido al tipo de cambio del documento; el total a pagar incluye los costos de llegada registrados. Cada pago ↔ un asiento del libro de caja; se anula desde la orden, no desde Finanzas. La diferencia cambiaria se difiere a una etapa posterior.
* Una orden con mercancía recibida o pagos activos no se cancela. Recepciones y pagos son solo-INSERT a nivel de base de datos.

## 10. Notas de la etapa 4e
- Reserva de stock según `order_reserve_stock_on` (pending | confirmed | never; por defecto confirmed), editable en Pedidos por quien tenga `settings.edit`. Invariante: `reserved_quantity` = suma de reservas activas (lo verifica `InventoryService::reconcile`).
- La salida de stock y el ingreso ocurren al **entregar** (`delivered_at`); `unit_cost_snapshot` congela el costo promedio. Mostrador: pendiente → entregado directo, sin reserva.
- Dinero recibido antes de entregar = `deposit` (pasivo); después = `payment`; `refund` saca dinero. Cancelar exige anticipos reembolsados. Se anula desde el pedido (`sales.refund`), nunca desde el libro.
- Devoluciones: importes proporcionales; la última devolución de una línea toma el resto exacto. Con reposición, el stock vuelve al costo original. El reembolso en dinero es un paso aparte (saldo a favor del cliente).
- Permisos: `sales.create` (borrador, líneas, enviar, confirmar), `sales.edit` (preparar, entregar), `sales.pay`, `sales.refund` (reembolsos y anulaciones), `sales.cancel`, `sales.return`.
- Ventas solo en moneda base (las cuentas ya lo son).
