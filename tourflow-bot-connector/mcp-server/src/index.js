// Servidor MCP (Streamable HTTP, JSON-RPC sin estado) que envuelve la API tfbc/v1.
// La API key de WordPress vive solo acá (secreto); el cliente MCP se autentica con MCP_TOKEN.
const J = (o) => ({ type: 'object', ...o });
const TOOLS = [
  { name: 'list_catalog', description: 'Lista tours/habitaciones/productos reservables.', method: 'GET', path: () => '/catalog', schema: J({ properties: { lang: { type: 'string' } } }) },
  { name: 'get_item', description: 'Detalle de un ítem.', method: 'GET', path: (a) => `/items/${a.type}/${a.id}`, query: ['lang'], schema: J({ required: ['type', 'id'], properties: { type: { enum: ['tour', 'room', 'product'] }, id: { type: 'integer' }, lang: { type: 'string' } } }) },
  { name: 'check_availability', description: 'Disponibilidad de un tour (date) o habitación (check_in, check_out).', method: 'GET', path: () => '/availability', query: ['type', 'id', 'date', 'check_in', 'check_out'], schema: J({ required: ['type', 'id'], properties: { type: { enum: ['tour', 'room'] }, id: { type: 'integer' }, date: { type: 'string' }, check_in: { type: 'string' }, check_out: { type: 'string' } } }) },
  { name: 'quote_tour', description: 'Cotiza un tour con precio real.', method: 'POST', path: () => '/quote', schema: J({ required: ['tour_id', 'date', 'adults'], properties: { tour_id: { type: 'integer' }, schedule_id: { type: 'integer' }, date: { type: 'string' }, adults: { type: 'integer' }, children: { type: 'integer' }, babies: { type: 'integer' }, coupon_code: { type: 'string' }, lang: { type: 'string' } } }) },
  { name: 'create_pending_booking', description: 'Crea una reserva PENDIENTE y devuelve payment_url. Antes confirmá con el cliente fecha, personas, total y que aceptó términos y política; nunca pongas true por él.', method: 'POST', path: () => '/book', schema: J({ required: ['tour_id', 'date', 'adults', 'customer_name', 'customer_email', 'policy_accepted', 'terms_accepted'], properties: { tour_id: { type: 'integer' }, schedule_id: { type: 'integer' }, date: { type: 'string' }, adults: { type: 'integer' }, children: { type: 'integer' }, babies: { type: 'integer' }, customer_name: { type: 'string' }, customer_email: { type: 'string' }, customer_phone: { type: 'string' }, lang: { type: 'string' }, coupon_code: { type: 'string' }, special_requests: { type: 'string' }, participant_names: { type: 'array', items: { type: 'string' } }, policy_accepted: { type: 'boolean' }, terms_accepted: { type: 'boolean' } } }) },
  { name: 'get_booking_status', description: 'Estado de una reserva (requiere ref y email).', method: 'GET', path: (a) => `/bookings/${encodeURIComponent(a.ref)}`, query: ['email'], schema: J({ required: ['ref', 'email'], properties: { ref: { type: 'string' }, email: { type: 'string' } } }) },
  { name: 'get_booking_link', description: 'Link a la ficha del ítem con fecha precargada.', method: 'GET', path: () => '/booking-link', query: ['type', 'id', 'date', 'lang'], schema: J({ required: ['type', 'id'], properties: { type: { type: 'string' }, id: { type: 'integer' }, date: { type: 'string' }, lang: { type: 'string' } } }) },
];

const rpc = (id, result) => ({ jsonrpc: '2.0', id, result });
const rpcErr = (id, code, message) => ({ jsonrpc: '2.0', id, error: { code, message } });

async function callTool(env, tool, args) {
  const url = new URL(env.TFBC_BASE_URL.replace(/\/$/, '') + tool.path(args));
  for (const k of tool.query || []) if (args[k] !== undefined) url.searchParams.set(k, args[k]);
  const init = { method: tool.method, headers: { 'X-TFBC-Api-Key': env.TFBC_API_KEY } };
  if (tool.method === 'POST') { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(args); }
  const res = await fetch(url, init);
  return { content: [{ type: 'text', text: await res.text() }], isError: !res.ok };
}

export default {
  async fetch(req, env) {
    if (req.method !== 'POST') return new Response('Method Not Allowed', { status: 405 });
    if (req.headers.get('Authorization') !== `Bearer ${env.MCP_TOKEN}`) return new Response('Unauthorized', { status: 401 });
    const m = await req.json();
    const h = { 'Content-Type': 'application/json' };
    if (m.id === undefined) return new Response(null, { status: 202 }); // notificación
    let out;
    if (m.method === 'initialize') out = rpc(m.id, { protocolVersion: '2025-03-26', capabilities: { tools: {} }, serverInfo: { name: 'tourflow', version: '0.2.0' } });
    else if (m.method === 'tools/list') out = rpc(m.id, { tools: TOOLS.map((t) => ({ name: t.name, description: t.description, inputSchema: t.schema })) });
    else if (m.method === 'tools/call') {
      const tool = TOOLS.find((t) => t.name === m.params?.name);
      out = tool ? rpc(m.id, await callTool(env, tool, m.params.arguments || {})) : rpcErr(m.id, -32602, 'Herramienta desconocida');
    } else out = rpcErr(m.id, -32601, 'Método no soportado');
    return new Response(JSON.stringify(out), { headers: h });
  },
};
