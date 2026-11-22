# BIC — Manual de usuario

BIC controla productos, inventario, compras, ventas, dinero y resultados de tu negocio. Todas las cifras están en **USD** y los impuestos se configuran en *Configuración*. Lo que ves depende de tu **rol**.

## Roles
| Rol | Puede |
|---|---|
| Administrador | Todo, incluidos usuarios, roles y configuración |
| Gerente | Operación y finanzas completas; no gestiona usuarios/roles ni edita configuración |
| Vendedor | Clientes y pedidos, consulta de catálogo (ve precios, no costos) |
| Analista | Solo lectura, reportes e inteligencia |

Si no ves un menú o recibes «403 Prohibido», tu rol no incluye ese permiso; pídelo al administrador.

## Flujos de trabajo

**1. Preparar el catálogo.** *Productos → Nuevo*: nombre, unidad, precio de venta, SKU y costo de referencia. Las variantes (talla, color) se agregan en la ficha. Los cambios de precio/costo quedan en un historial.

**2. Comprar a un proveedor.** *Compras → Nueva*: elige proveedor y almacén, agrega líneas (cantidad, precio, impuesto y si es recuperable) y costos de llegada (flete, aduana). *Enviar* bloquea las líneas. *Recibir* acepta recepciones parciales y actualiza el **costo promedio**. *Pagar* crea el egreso en la cuenta elegida; se puede pagar en partes pero nunca más de lo pendiente. Una compra no es un gasto: el gasto se reconoce al vender.

**3. Vender.** *Pedidos → Nuevo*: cliente (opcional), almacén y líneas. Estados: borrador → pendiente → confirmado → preparado → entregado (o cancelado). El stock se **reserva** según la configuración (`order_reserve_stock_on`, por defecto al confirmar) y sale del inventario al **entregar**, congelando el costo. Puedes registrar anticipos y cobros (un doble clic no cobra dos veces), reembolsos y anular un cobro.

**4. Devoluciones.** Desde un pedido entregado: elige cantidades y si el producto vuelve al inventario. El reembolso es proporcional; la última devolución toma el resto para no perder centavos.

**5. Gastos.** *Gastos → Nuevo*: categoría, cuenta, monto, fecha. Un gasto anulado no cuenta en resultados.

**6. Dinero.** *Finanzas*: cuentas, saldos (calculados del libro, nunca editados a mano), traspasos entre cuentas y movimientos de capital. No se permiten saldos negativos.

**7. Resultados.** *Gastos → Resultados*: ingresos (pedidos entregados − devoluciones) − costo de lo vendido = utilidad bruta; menos gastos = utilidad neta.

**8. Investigación de mercado.** Registra artículos y precios observados; BIC compara contra tu costo y precio.

**9. Dashboard, Reportes e Inteligencia.** El dashboard muestra indicadores según tu permiso. *Reportes* exporta a **CSV, Excel y PDF**. *Inteligencia* sugiere reabastecer, detecta márgenes bajos y productos sin rotación (umbrales en Configuración).

**10. Asistente de IA.** Pregunta en lenguaje natural («¿cuánto vendí este mes?»). Solo lee datos agregados que tu rol puede ver, no inventa cifras ni modifica nada. Límite diario configurable.

## Buenas prácticas
- Corrige con documentos inversos (anular, devolver), no esperando editar el pasado: el historial, el kardex y la auditoría son inmutables.
- Cierra sesión al terminar; tras varios intentos fallidos la cuenta se bloquea temporalmente.
- Usa una contraseña larga y única.
