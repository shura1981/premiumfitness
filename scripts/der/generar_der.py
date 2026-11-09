"""Genera el DER de PremiumFitness (draw.io, multipágina).

Las tablas que vienen de backend-radical-nutrition se construyen leyendo su esquema
real (dump SQL + migraciones): mismas columnas, mismo orden y mismos tipos. Las
columnas que añade PremiumFitness van al final de la tabla, marcadas con «+».

Uso:
    python3 scripts/der/generar_der.py docs/img/diagrama-entidad-relacion.drawio [ruta-radical]
"""
import os
import re
import sys
from xml.sax.saxutils import escape

RADICAL = os.environ.get("RADICAL_REPO", "/home/steven/Proyectos/php/backend-radical-nutrition")


# =====================================================================
# Esquema real de radical
# =====================================================================
def parse_radical(repo):
    dump = os.path.join(repo, "docs/database/schema/crisenri_radical_nutrition_clear.sql")
    sql = open(dump, encoding="utf-8", errors="replace").read()
    tables = {}
    for m in re.finditer(r"CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE", sql, re.S):
        cols = []
        for line in m.group(2).split("\n"):
            line = line.strip().rstrip(",")
            cm = re.match(r"`(\w+)`\s+(.+)$", line)
            if not cm:
                continue
            name, rest = cm.groups()
            typ = re.match(r"(enum\([^)]*\)|[a-z]+(?:\(\d+(?:,\d+)?\))?)", rest).group(1)
            cols.append({"name": name, "type": typ, "null": "NOT NULL" not in rest})
        tables[m.group(1)] = {"cols": cols, "pk": [], "uq": [], "fk": {}, "ai": None}
    for m in re.finditer(r"ALTER TABLE `(\w+)`\n(.*?);", sql, re.S):
        t, body = m.groups()
        if t not in tables:
            continue
        tb = tables[t]
        pk = re.search(r"ADD PRIMARY KEY \(([^)]*)\)", body)
        if pk:
            tb["pk"] = re.findall(r"`(\w+)`", pk.group(1))
        for uq in re.finditer(r"ADD UNIQUE KEY `\w+` \(([^)]*)\)", body):
            tb["uq"].append(re.findall(r"`(\w+)`", uq.group(1)))
        for fk in re.finditer(r"FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\)", body):
            tb["fk"][fk.group(1)] = f"{fk.group(2)}.{fk.group(3)}"
        ai = re.search(r"MODIFY `(\w+)` [^,;]*AUTO_INCREMENT", body)
        if ai:
            tb["ai"] = ai.group(1)

    def col(t, c):
        return next(x for x in tables[t]["cols"] if x["name"] == c)

    # migrations/migrate_users_id_bigint.sql
    col("users", "id")["type"] = "bigint(20)"
    for t in ("address_user_map", "credit_balances", "orders", "password_reset_tokens", "verification_codes"):
        col(t, "user_id")["type"] = "bigint(20)"
    # migrations/add_retail_account_email_verification.sql (AFTER password)
    u = tables["users"]["cols"]
    if not any(c["name"] == "email_verified_at" for c in u):
        i = next(i for i, c in enumerate(u) if c["name"] == "password")
        u.insert(i + 1, {"name": "email_verified_at", "type": "timestamp", "null": True})
    # migrations/add_products_updated_at.sql
    if not any(c["name"] == "updated_at" for c in tables["products"]["cols"]):
        tables["products"]["cols"].append({"name": "updated_at", "type": "timestamp", "null": False})
    # migrations/create_retail_checkout_support.sql
    tables["orders_coupons"] = {
        "cols": [{"name": "id", "type": "bigint(20)", "null": False},
                 {"name": "order_id", "type": "bigint(20)", "null": False},
                 {"name": "coupon_code", "type": "varchar(50)", "null": False},
                 {"name": "discount_percentage", "type": "decimal(5,2)", "null": False},
                 {"name": "discount_amount", "type": "decimal(12,2)", "null": False},
                 {"name": "created_at", "type": "timestamp", "null": False}],
        "pk": ["id"], "uq": [["order_id"]], "fk": {"order_id": "orders.order_id"}, "ai": "id"}
    return tables


SCHEMA = parse_radical(sys.argv[2] if len(sys.argv) > 2 else RADICAL)

# =====================================================================
# Dibujo
# =====================================================================
ROW, HEAD = 24, 30
ORIGIN = {
    "E": ("#f5f5f5", "#666666", "solid"),   # existente en radical
    "A": ("#d5e8d4", "#82b366", "solid"),   # adaptada de radical
    "N": ("#dae8fc", "#6c8ebf", "solid"),   # nueva
    "S": ("#ffffff", "#999999", "dashed"),  # referencia a otra página
    "X": ("#e1d5e7", "#9673a6", "solid"),   # externa: crisenri_intranet
}
FK_FILL = "#fff2cc"
ONE, ZONE, MANY0, MANY1 = "ERmandOne", "ERzeroToOne", "ERzeroToMany", "ERoneToMany"


def esc(s):
    return escape(s, {'"': "&quot;"})


def r(key, name, typ="", mark=""):
    return (key, name, typ, mark)


def rad(table, notes=None, fk=(), mods=None, extra=(), drop=()):
    """Filas de una tabla de radical, exactamente como en su esquema.
    notes: comentario por columna · fk: columnas FK sin constraint en radical ·
    mods: {columna: tipo nuevo} (se marca «~») · extra: filas nuevas (se marcan «+») ·
    drop: columnas que PremiumFitness no conserva."""
    tb = SCHEMA[table]
    notes, mods = notes or {}, mods or {}
    rows = []
    for c in tb["cols"]:
        n = c["name"]
        if n in drop:
            continue
        key = []
        if n in tb["pk"]:
            key.append("PK")
        if n in tb["fk"] or n in fk:
            key.append("FK")
        if [n] in tb["uq"]:
            key.append("UQ")
        typ = mods.get(n, c["type"] + (" (AI)" if tb["ai"] == n else "") + (" NULL" if c["null"] else ""))
        if n in notes:
            typ += " · " + notes[n]
        rows.append(r(" ".join(key), n, typ, "~" if n in mods else ""))
    return rows + [r(k, n, t, "+") for k, n, t in extra]


class Page:
    def __init__(self, name, pid):
        self.name, self.pid = name, pid
        self.cells, self.t, self.n = [], {}, 0

    def nid(self):
        self.n += 1
        return f"{self.pid}-{self.n}"

    def table(self, tid, title, origin, x, y, rows, w=None):
        fill, stroke, dash = ORIGIN[origin]
        width = w or max(220, int(max(len(title) * 9, max(rowlen(x_) for x_ in rows) * 6.4)) + 24)
        width = (width + 9) // 10 * 10
        h = HEAD + ROW * len(rows) + 6
        style = (f"swimlane;html=1;startSize={HEAD};fontStyle=1;fontSize=13;collapsible=0;"
                 f"fillColor={fill};strokeColor={stroke};swimlaneFillColor=#ffffff;"
                 + ("dashed=1;fontColor=#777777;" if dash == "dashed" else ""))
        self.cells.append(f'<mxCell id="{tid}" value="{esc(title)}" style="{style}" vertex="1" parent="1">'
                          f'<mxGeometry x="{x}" y="{y}" width="{width}" height="{h}" as="geometry"/></mxCell>')
        rowids = {}
        for i, (key, name, typ, mark) in enumerate(rows):
            col = name.split(' ')[0]
            rid = f"{tid}.{col}" if col not in rowids else f"{tid}.{col}~{i}"  # ids únicos
            rowids.setdefault(col, (rid, i))
            label = row_label(key, name, typ, mark, origin == "S")
            st = "text;html=1;align=left;verticalAlign=middle;spacingLeft=8;spacingRight=4;fontSize=12;overflow=hidden;"
            if "FK" in key and origin != "S":
                st += f"fillColor={FK_FILL};"
            self.cells.append(f'<mxCell id="{rid}" value="{esc(label)}" style="{st}" vertex="1" parent="{tid}">'
                              f'<mxGeometry y="{HEAD + ROW * i}" width="{width}" height="{ROW}" as="geometry"/></mxCell>')
        self.t[tid] = dict(x=x, y=y, w=width, h=h, rows=rowids)
        return self

    def L(self, tid):
        return self.t[tid]["x"]

    def R(self, tid):
        return self.t[tid]["x"] + self.t[tid]["w"]

    def T(self, tid):
        return self.t[tid]["y"]

    def B(self, tid):
        return self.t[tid]["y"] + self.t[tid]["h"]

    def ry(self, tid, col):
        return self.t[tid]["y"] + HEAD + ROW * self.t[tid]["rows"][col][1] + ROW // 2

    def rel(self, src, dst, s_sym, d_sym, s_side="R", d_side="L", pts=None, label="", dashed=False):
        ex, ey = side(s_side)
        nx, ny = side(d_side)
        st = (f"edgeStyle=orthogonalEdgeStyle;html=1;rounded=0;jumpStyle=arc;jumpSize=8;"
              f"startArrow={s_sym};endArrow={d_sym};startFill=0;endFill=0;startSize=10;endSize=10;"
              f"strokeColor=#4d4d4d;strokeWidth=1.2;fontSize=10;labelBackgroundColor=#ffffff;"
              f"exitX={ex};exitY={ey};exitDx=0;exitDy=0;entryX={nx};entryY={ny};entryDx=0;entryDy=0;"
              + ("dashed=1;" if dashed else ""))
        geo = '<mxGeometry relative="1" as="geometry">'
        if pts:
            geo += '<Array as="points">' + "".join(f'<mxPoint x="{px}" y="{py}"/>' for px, py in pts) + "</Array>"
        geo += "</mxGeometry>"
        self.cells.append(f'<mxCell id="{self.nid()}" value="{esc(label)}" style="{st}" edge="1" parent="1" '
                          f'source="{self._cell(src)}" target="{self._cell(dst)}">{geo}</mxCell>')

    def _cell(self, ref):
        if "." in ref:
            t, c = ref.split(".", 1)
            return self.t[t]["rows"][c][0]
        return ref

    def text(self, x, y, w, h, html, style=""):
        st = "text;html=1;align=left;verticalAlign=top;whiteSpace=wrap;" + style
        self.cells.append(f'<mxCell id="{self.nid()}" value="{esc(html)}" style="{st}" vertex="1" parent="1">'
                          f'<mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry"/></mxCell>')

    def note(self, x, y, w, h, html):
        self.text(x, y, w, h, html, "fontSize=12;rounded=1;arcSize=4;fillColor=#fffdf5;strokeColor=#d6b656;spacing=12;")

    def box(self, x, y, w, h, style, value=""):
        self.cells.append(f'<mxCell id="{self.nid()}" value="{esc(value)}" style="{style}" vertex="1" parent="1">'
                          f'<mxGeometry x="{x}" y="{y}" width="{w}" height="{h}" as="geometry"/></mxCell>')

    def header(self, title, subtitle):
        self.text(40, 20, 1800, 30, f"<b>{title}</b>", "fontSize=20;")
        self.text(40, 52, 1800, 22, subtitle, "fontSize=12;fontColor=#666666;")

    def legend(self, x, y):
        w = 360
        self.box(x, y, w, 320, "rounded=1;arcSize=4;fillColor=#ffffff;strokeColor=#bbbbbb;")
        self.text(x + 14, y + 10, 200, 20, "<b>LEYENDA</b>", "fontSize=12;")
        items = [("#f5f5f5", "#666666", "", "Existente en radical, idéntica"),
                 ("#d5e8d4", "#82b366", "", "Adaptada de radical"),
                 ("#dae8fc", "#6c8ebf", "", "Nueva en PremiumFitness"),
                 ("#ffffff", "#999999", "dashed=1;", "Referencia: se detalla en otra página"),
                 ("#e1d5e7", "#9673a6", "", "Externa: intranet (crisenri_intranet)"),
                 (FK_FILL, "#d6b656", "", "Fila FK (clave foránea)")]
        for i, (f, s, d, t) in enumerate(items):
            yy = y + 38 + i * 24
            self.box(x + 14, yy + 3, 24, 16, f"rounded=0;fillColor={f};strokeColor={s};{d}")
            self.text(x + 46, yy, 300, 22, t, "fontSize=11;verticalAlign=middle;")
        yy = y + 38 + len(items) * 24
        self.text(x + 14, yy, 330, 22, '<b style="color:#1a7f37">+ columna</b> nueva, al final de la tabla de radical', "fontSize=11;")
        self.text(x + 14, yy + 20, 330, 22, '<b style="color:#b45309">~ columna</b> de radical modificada', "fontSize=11;")
        self.text(x + 14, yy + 44, 330, 40,
                  "Tablas de radical: columnas, orden y tipos tomados de su esquema SQL y sus migraciones.",
                  "fontSize=11;fontColor=#444444;")
        self.text(x + 14, yy + 84, 330, 40,
                  "Cardinalidad: ┼┼ uno · ○┼ cero o uno<br>○&lt; cero o muchos · ┼&lt; uno o muchos",
                  "fontSize=11;fontColor=#444444;")

    def xml(self):
        body = "".join(self.cells)
        return (f'<diagram name="{esc(self.name)}" id="{self.pid}"><mxGraphModel dx="1600" dy="1000" grid="1" gridSize="10" '
                f'guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="0" pageScale="1" math="0" shadow="0">'
                f'<root><mxCell id="0"/><mxCell id="1" parent="0"/>{body}</root></mxGraphModel></diagram>')


def side(s):
    return {"R": (1, 0.5), "L": (0, 0.5), "T": (0.5, 0), "B": (0.5, 1)}.get(s, s) if isinstance(s, str) else s


def rowlen(row):
    key, name, typ, mark = row
    return 6 + len(name) + 3 + len(typ) + (2 if mark else 0)


def row_label(key, name, typ, mark, stub):
    k = f'<span style="display:inline-block;width:44px;font-weight:bold;color:#555555">{key}</span>'
    nm = f"<u>{name}</u>" if key.startswith("PK") else name
    if mark == "+":
        nm = f'<span style="color:#1a7f37;font-weight:bold">+ {nm}</span>'
    elif mark == "~":
        nm = f'<span style="color:#b45309;font-weight:bold">~ {nm}</span>'
    t = f' <span style="color:#777777">: {typ}</span>' if typ else ""
    if stub:
        return f'<span style="color:#888888">{k}{nm}{t}</span>'
    return k + nm + t


# =====================================================================
# Diagrama único de la base de datos
# =====================================================================
db = Page("Base de datos", "db")
db.header("PremiumFitness — Diagrama entidad relación de la base de datos",
          "v0.8 · 9 nov 2026 · base: esquema real de backend-radical-nutrition (dump + migraciones) sin credit_balances, "
          "más las tablas nuevas de referidos, comisiones, puntos y operación · spec: specs/diseño/01-DER.md")
Y0 = 170
ry = db.ry

# --- A. Identidad y direcciones (radical) -----------------------------
db.table("dt", "document_types", "E", 40, Y0, rad("document_types"))
db.table("vc", "verification_codes", "E", 40, db.B("dt") + 40, rad("verification_codes"))
db.table("prt", "password_reset_tokens", "E", 40, db.B("vc") + 40, rad("password_reset_tokens"))
UX = max(db.R("vc"), db.R("prt"), db.R("dt")) + 90
db.table("users", "users", "A", UX, Y0, rad("users", extra=[
    ("", "phone_verified_at", "timestamp NULL"),
    ("", "role", "enum('client','trainer')"),
    ("", "status", "enum('pending','active','suspended')")]))
AX = db.R("users") + 110
db.table("aum", "address_user_map", "E", AX, Y0, rad("address_user_map"))
AD = db.R("aum") + 90
db.table("addr", "addresses", "E", AD, Y0, rad("addresses"))
db.table("alm", "address_locality_map", "E", AD, db.B("addr") + 50, rad("address_locality_map"), w=db.t["addr"]["w"])
CX = db.R("addr") + 90
db.table("cities", "cities", "E", CX, Y0, rad("cities", notes={"dan_code": "DANE"}))
db.table("loc", "localities", "E", CX, db.T("alm"), rad("localities"), w=db.t["cities"]["w"])
db.table("cw", "city_warehouses", "E", CX, db.B("loc") + 50, rad("city_warehouses", notes={
    "warehouse_1": "bodega SAP principal", "warehouse_2": "bodega SAP 2", "warehouse_3": "bodega SAP 3"}))

xb = UX - 40
db.rel("users.id", "vc.user_id", ONE, MANY0, "L", "R", [(xb, ry("users", "id")), (xb, ry("vc", "user_id"))])
db.rel("users.id", "prt.user_id", ONE, MANY0, "L", "R", [(xb, ry("users", "id")), (xb, ry("prt", "user_id"))])
db.rel("dt.id", "users.document_type_id", ONE, MANY0, "R", "L",
       [(xb + 20, ry("dt", "id")), (xb + 20, ry("users", "document_type_id"))])
db.rel("users.id", "aum.user_id", ONE, MANY0, "R", "L")
db.rel("addr.id", "aum.address_id", ONE, MANY0, "L", "R",
       [(AD - 40, ry("addr", "id")), (AD - 40, ry("aum", "address_id"))])
db.rel("cities.id", "addr.city_id", ONE, MANY0, "L", "R",
       [(CX - 40, ry("cities", "id")), (CX - 40, ry("addr", "city_id"))])
db.rel("addr", "alm", ONE, ZONE, "B", "T")
db.rel("loc.id", "alm.locality_id", ONE, MANY0, "L", "R",
       [(CX - 40, ry("loc", "id")), (CX - 40, ry("alm", "locality_id"))])
xcw = max(db.R("cities"), db.R("loc"), db.R("cw")) + 30
db.rel("cities.id", "cw.city_id", ONE, MANY0, "R", "R",
       [(xcw, ry("cities", "id")), (xcw, ry("cw", "city_id"))], label="bodegas SAP")

# --- B. Entrenadores y referidos (nuevas) -----------------------------
Y1 = db.B("users") + 130
db.table("tr", "trainers", "N", UX, Y1, [
    r("PK FK", "user_id", "bigint(20)"), r("UQ", "coupon_code", "varchar(30) · cupón del entrenador"),
    r("", "joined_at", "timestamp"), r("", "updated_at", "timestamp")], w=380)
db.table("cr", "client_referrals", "N", AX, Y1, [
    r("PK", "id", "bigint(20) (AI)"), r("FK UQ", "client_id", "bigint(20) · un entrenador por cliente"),
    r("FK", "trainer_id", "bigint(20)"), r("", "source", "enum('link','coupon')"), r("", "linked_at", "timestamp")])
db.table("trf", "trainer_referrals", "N", AX, db.B("cr") + 110, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "referrer_id", "bigint(20) · A"), r("FK UQ", "referred_id", "bigint(20) · B"),
    r("", "referral_points", "int(11) · 50.000 (env) al registrarse B"),
    r("FK UQ", "activation_order_id", "bigint(20) NULL · 1.ª compra de cliente de B"),
    r("", "registered_at", "timestamp"), r("", "activated_at", "timestamp NULL · NULL = pendiente")])

ux_mid = db.L("tr") + 150
db.rel("users", "tr", ONE, ZONE, ((ux_mid - db.L("users")) / db.t["users"]["w"], 1), (0.5, 0))
band = db.B("users") + 60
xu = db.R("users") + 40
db.rel("users.id", "cr.client_id", ONE, ZONE, "R", "L",
       [(xu, ry("users", "id")), (xu, band), (AX - 30, band), (AX - 30, ry("cr", "client_id"))], label="cliente")
yt = ry("tr", "user_id")
db.rel("tr.user_id", "cr.trainer_id", ONE, MANY0, "R", "L",
       [(AX - 50, yt), (AX - 50, ry("cr", "trainer_id"))])
db.rel("tr.user_id", "trf.referrer_id", ONE, MANY0, "R", "L",
       [(db.R("tr") + 40, yt), (db.R("tr") + 40, ry("trf", "referrer_id"))], label="A")
db.rel("tr.user_id", "trf.referred_id", ONE, ZONE, "R", "L",
       [(db.R("tr") + 60, yt), (db.R("tr") + 60, ry("trf", "referred_id"))], label="B")

# --- C. Pedidos, catálogo y SAP (radical) ------------------------------
ZC = max(db.R("cities"), db.R("cw"), db.R("cr"), db.R("trf")) + 260
db.table("ord", "orders", "A", ZC, Y0, rad("orders",
         drop=("balance_id",), fk=("agent_id",),
         notes={"user_id": "comprador", "agent_id": "agente call center", "amount": "total",
                "sap_order_code": "pedido SAP"},
         mods={"payment_gateway": "enum('epayco','addi','sistecredito','bold') NULL"},
         extra=[("", "order_type", "enum('client_purchase','trainer_purchase','redemption')"),
                ("UQ", "payment_reference", "varchar(100) NULL"),
                ("", "subtotal_full", "decimal(12,2)"),
                ("", "discount_amount", "decimal(12,2)"),
                ("", "tax_base", "decimal(12,2)"),
                ("", "tax_amount", "decimal(12,2)"),
                ("", "reward_percentage", "decimal(5,2) NULL · 5% (env) al comprar"),
                ("", "reward_points", "int(11) NULL · 5% de lo pagado, redondeado"),
                ("", "redemption_points", "int(11) NULL · costo del canje"),
                ("", "approved_at", "timestamp NULL")]))
OIX = db.R("ord") + 100
db.table("oi", "orders_items", "A", OIX, Y0, rad("orders_items",
         notes={"sap_code": "artículo SAP (sin FK)", "discount_percentage": "descuento vigente del producto"},
         extra=[("", "tax_rate", "decimal(5,2)"), ("", "tax_base", "decimal(12,2)"),
                ("", "tax_amount", "decimal(12,2)"),
                ("", "commission_percentage", "decimal(5,2) NULL"),
                ("", "commission_amount", "decimal(12,2) NULL · sobre tax_base"),
                ("", "redemption_points", "int(11) NULL · canje")]))
OW = db.t["oi"]["w"]
db.table("oc", "orders_coupons", "A", OIX, db.B("oi") + 50,
         rad("orders_coupons", notes={"order_id": "un cupón por pedido", "coupon_code": "copia de trainers.coupon_code"},
             extra=[("FK", "trainer_id", "bigint(20) · atribución")]), w=OW)
db.table("pej", "payment_event_jobs", "E", OIX, db.B("oc") + 50, rad("payment_event_jobs"), w=OW)
db.table("so", "status_orders", "E", OIX, db.B("pej") + 50, rad("status_orders"), w=OW)
PX = OIX + OW + 100
db.table("prod", "products", "A", PX, Y0, rad("products",
         notes={"price": "IVA incluido", "discount_list": "tramos con sap_code"},
         extra=[("", "tax_rate", "decimal(5,2)")]))
db.table("pgm", "product_group_map", "E", PX, db.B("prod") + 60, rad("product_group_map"), w=db.t["prod"]["w"])
db.table("pg", "product_groups", "E", PX, db.B("pgm") + 60,
         rad("product_groups", notes={"name": "presentaciones de un producto"}), w=db.t["prod"]["w"])
RCX = db.R("prod") + 110
db.table("pdr", "product_discount_rules", "N", RCX, Y0, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "product_sku", "bigint(20)"),
    r("", "client_discount_percentage", "decimal(5,2) NULL · con cupón"),
    r("", "trainer_discount_percentage", "decimal(5,2) NULL"),
    r("", "commission_percentage", "decimal(5,2) NULL"),
    r("", "valid_from", "timestamp"), r("", "valid_to", "timestamp NULL"),
    r("", "is_active", "bit(1)"), r("", "created_at / updated_at", "timestamp")])
db.table("sp", "sap_products", "E", RCX, db.B("pdr") + 60, rad("sap_products"), w=db.t["pdr"]["w"])
db.table("psm", "product_sap_map", "E", RCX, db.B("sp") + 60, rad("product_sap_map"), w=db.t["sp"]["w"])
db.table("cat", "categories", "E", RCX, db.B("psm") + 60, rad("categories"), w=db.t["sp"]["w"])

yoid = ry("ord", "order_id")
xo = db.R("ord") + 40
for t in ("oi", "oc", "pej"):
    card = MANY1 if t == "oi" else ZONE
    db.rel("ord.order_id", f"{t}.order_id", ONE, card, "R", "L",
           [(xo, yoid), (xo, ry(t, "order_id"))], label={"oc": "cupón"}.get(t, ""))
db.rel("so.status_id", "ord.status_id", ZONE, MANY0, "L", "R",
       [(db.R("ord") + 20, ry("so", "status_id")), (db.R("ord") + 20, ry("ord", "status_id"))])
db.rel("prod.sku", "oi.product_sku", ONE, MANY0, "L", "R",
       [(PX - 40, ry("prod", "sku")), (PX - 40, ry("oi", "product_sku"))])
db.rel("prod.sku", "pdr.product_sku", ONE, MANY0, "R", "L",
       [(db.R("prod") + 80, ry("prod", "sku")), (db.R("prod") + 80, ry("pdr", "product_sku"))], label="estrategia")
db.rel("prod.sku", "psm.product_sku", ONE, MANY0, "R", "L",
       [(db.R("prod") + 60, ry("prod", "sku")), (db.R("prod") + 60, ry("psm", "product_sku"))])
xsp = db.R("sp") + 30
db.rel("sp.id", "psm.sap_id", ONE, MANY0, "R", "R", [(xsp, ry("sp", "id")), (xsp, ry("psm", "sap_id"))])
db.rel("cat.id", "prod.category_id", ONE, MANY0, "L", "R",
       [(db.R("prod") + 30, ry("cat", "id")), (db.R("prod") + 30, ry("prod", "category_id"))])
db.rel("prod", "pgm", ONE, MANY0, "B", "T", label="sku")
db.rel("pg", "pgm", ONE, MANY0, "T", "B", label="grupo")
# llegadas a orders desde la izquierda, por corredores superiores
wu = db.t["users"]["w"]
db.rel("users", "ord.user_id", ONE, MANY0, ((wu - 40) / wu, 0), "L",
       [(UX + wu - 40, 105), (ZC - 30, 105), (ZC - 30, ry("ord", "user_id"))], label="comprador")
wa = db.t["addr"]["w"]
db.rel("addr", "ord.address_id", ZONE, MANY0, ((wa - 30) / wa, 0), "L",
       [(AD + wa - 30, 125), (ZC - 50, 125), (ZC - 50, ry("ord", "address_id"))], label="entrega")

# --- D. Comisiones, puntos y operación (nuevas) ------------------------
yD = db.B("trf") + 140
dx, dy = UX - 20, yD - 100
yo = yD + 42                # corredor de orders hacia las tablas de recompensas
B185, BAND_T = 185 + dy, 225 + dy
CMX = 200 + dx
Y3 = 270 + dy
db.table("cm", "commission_movements", "N", CMX, Y3, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "order_id", "bigint(20) NULL"), r("FK", "trainer_id", "bigint(20)"),
    r("FK UQ", "conversion_id", "bigint(20) NULL"), r("FK", "adjustment_id", "bigint(20) NULL"),
    r("FK", "settlement_id", "bigint(20) NULL · liquidación del mes"),
    r("", "type", "enum('sale_credit','conversion_debit','adjustment','settlement_debit')"),
    r("", "tax_base", "decimal(12,2) NULL"),
    r("", "amount", "decimal(12,2) · Σ líneas · + abono / − débito"),
    r("UQ", "source_key", "varchar(64)"), r("", "created_at", "timestamp")])
CCX = db.R("cm") + 140
db.table("cc", "commission_conversions", "N", CCX, Y3 + 40, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "trainer_id", "bigint(20)"),
    r("", "commission_amount", "decimal(12,2) · C"), r("", "base_points", "int(11) · P vigentes"),
    r("", "conversion_percentage", "decimal(5,2) · 50% (env)"),
    r("", "bonus_points", "int(11) · mín(50% × P, C)"), r("", "points_granted", "int(11) · C + adicional"), r("UQ", "idempotency_key", "varchar(64)"), r("", "created_at", "timestamp")])
PLX = db.R("cc") + 150
db.table("pl", "point_lots", "N", PLX, Y3, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "order_id", "bigint(20) NULL"), r("FK", "trainer_id", "bigint(20)"),
    r("FK UQ", "conversion_id", "bigint(20) NULL"), r("FK", "adjustment_id", "bigint(20) NULL"),
    r("FK UQ", "trainer_referral_id", "bigint(20) NULL"),
    r("", "source", "enum('client_sale','trainer_referral','conversion','restitution')"),
    r("", "initial_points", "int(11)"), r("", "remaining_points", "int(11)"),
    r("", "status", "enum('available','used','expired')"),
    r("", "accrued_at", "timestamp · orden FIFO"),
    r("", "expires_at", "timestamp · accrued_at + 6 meses (env)"), r("UQ", "source_key", "varchar(64)")])
PMX = db.R("pl") + 150
db.table("pm", "point_movements", "N", PMX, Y3, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "point_lot_id", "bigint(20)"), r("FK", "order_id", "bigint(20) NULL · canje"),
    r("FK", "adjustment_id", "bigint(20) NULL"),
    r("", "type", "enum('credit','redemption','expiration','adjustment')"),
    r("", "points", "int(11) · + / −"), r("UQ", "source_key", "varchar(64)"), r("", "created_at", "timestamp")])
YB = db.B("pl") + 90
db.table("adj", "adjustments", "N", CMX + 120, YB, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "order_id", "bigint(20)"), r("FK", "agent_id", "int(11) · agente"),
    r("", "type", "enum('cancellation','full_return','partial_return','restitution','correction')"),
    r("", "reason", "text"), r("", "created_at", "timestamp")])
db.table("emp", "tb_empleados · externa (crisenri_intranet)", "X", 20 + dx, db.B("adj") + 60, [
    r("PK", "id_usuario", "int · = agent_id"), r("", "usuario", "varchar · nombre"),
    r("UQ", "email / pass", "varchar · login del admin"), r("", "cargo / departamento / sede", "varchar")])
db.table("al", "audit_logs", "N", db.R("emp") + 100, db.B("adj") + 60, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "agent_id", "int(11) NULL · agente"),
    r("FK", "user_id", "bigint(20) NULL · cliente o entrenador"), r("", "action", "varchar(60)"),
    r("", "entity / entity_id", "varchar(60) / bigint(20)"), r("", "data", "json"), r("", "created_at", "timestamp")])
db.table("cs", "commission_settlements", "N", db.R("al") + 100, db.B("adj") + 60, [
    r("PK", "id", "bigint(20) (AI)"), r("FK", "period_id", "bigint(20)"), r("FK", "trainer_id", "bigint(20)"),
    r("UQ", "period_id + trainer_id", "una liquidación por mes"),
    r("", "orders_count", "int(11)"),
    r("", "commission_amount", "decimal(12,2) · ventas del mes"),
    r("", "conversions_amount", "decimal(12,2) · convertido a puntos"),
    r("", "adjustments_amount", "decimal(12,2)"), r("", "total_amount", "decimal(12,2) · a pagar"),
    r("", "closed_at", "timestamp")])
db.table("cper", "commission_periods", "N", db.R("cs") + 100, db.B("adj") + 60, [
    r("PK", "id", "bigint(20) (AI)"), r("UQ", "period", "char(7) · '2026-11'"),
    r("", "period_start / period_end", "date"), r("", "closed_at", "timestamp"),
    r("", "trainers_count / orders_count", "int(11)"), r("", "total_amount", "decimal(12,2)"),
    r("", "file_name", "varchar(150) NULL · Excel privado"),
    r("", "report_sent_at", "timestamp NULL"), r("", "report_recipients", "varchar(500) NULL · copia del env")])

wt = db.t["tr"]["w"]
txr = lambda x: ((x - db.L("tr")) / wt, 1)  # noqa: E731
xt1, xt2 = 150 + dx, 235 + dx
db.rel("tr", "cm.trainer_id", ONE, MANY0, txr(xt1), "L", [(xt1, ry("cm", "trainer_id"))])
db.rel("tr", "cc.trainer_id", ONE, MANY0, txr(xt2), "L",
       [(xt2, BAND_T), (CCX - 30, BAND_T), (CCX - 30, ry("cc", "trainer_id"))])
db.rel("tr", "pl.trainer_id", ONE, MANY0, txr(xt2), "L",
       [(xt2, BAND_T), (PLX - 70, BAND_T), (PLX - 70, ry("pl", "trainer_id"))])
# orders → recompensas: sale por debajo de orders y baja al corredor yo
wo = db.t["ord"]["w"]
xot = ZC + 120
osrc = (120 / wo, 1)
db.rel("ord", "cm.order_id", ONE, MANY0, osrc, "L",
       [(xot, yo), (CCX, yo), (CCX, B185), (175 + dx, B185), (175 + dx, ry("cm", "order_id"))], label="15%")
db.rel("ord", "pl.order_id", ONE, MANY0, osrc, "L",
       [(xot, yo), (PLX - 40, yo), (PLX - 40, ry("pl", "order_id"))], label="5%")
db.rel("ord", "pm.order_id", ZONE, MANY0, osrc, "L",
       [(xot, yo), (PMX - 40, yo), (PMX - 40, ry("pm", "order_id"))], label="canje")
db.rel("ord", "adj.order_id", ONE, MANY0, osrc, "L",
       [(xot, yo), (CCX, yo), (CCX, B185), (175 + dx, B185), (175 + dx, ry("adj", "order_id"))])
yc = ry("cc", "id")
db.rel("cc.id", "cm.conversion_id", ONE, ONE, "L", "R",
       [(CCX - 70, yc), (CCX - 70, ry("cm", "conversion_id"))], label="débito")
db.rel("cc.id", "pl.conversion_id", ONE, ONE, "R", "L",
       [(PLX - 100, yc), (PLX - 100, ry("pl", "conversion_id"))], label="crédito")
ya = ry("adj", "id")
xg = db.R("cm") + 35
db.rel("adj", "cm.adjustment_id", ZONE, MANY0, ((xg - db.L("adj")) / db.t["adj"]["w"], 0), "R",
       [(xg, ry("cm", "adjustment_id"))])
db.rel("adj.id", "pl.adjustment_id", ZONE, MANY0, "R", "L",
       [(PLX - 120, ya), (PLX - 120, ry("pl", "adjustment_id"))], label="restitución")
db.rel("adj.id", "pm.adjustment_id", ZONE, MANY0, "R", "R",
       [(db.R("pm") + 30, ya), (db.R("pm") + 30, ry("pm", "adjustment_id"))])
db.rel("pl.id", "pm.point_lot_id", ONE, MANY1, "R", "L",
       [(PMX - 100, ry("pl", "id")), (PMX - 100, ry("pm", "point_lot_id"))], label="consumo FIFO")
db.rel("emp.id_usuario", "adj.agent_id", ONE, MANY0, "R", "L",
       [(db.L("adj") - 50, ry("emp", "id_usuario")), (db.L("adj") - 50, ry("adj", "agent_id"))], label="agente")
db.rel("emp.id_usuario", "al.agent_id", ZONE, MANY0, "R", "L",
       [(db.L("al") - 40, ry("emp", "id_usuario")), (db.L("al") - 40, ry("al", "agent_id"))])
xcs = db.L("cs") + 60
db.rel("cs", "cm.settlement_id", ZONE, MANY0, ((xcs - db.L("cs")) / db.t["cs"]["w"], 0), "R",
       [(xcs, YB - 30), (db.R("cm") + 60, YB - 30),
        (db.R("cm") + 60, ry("cm", "settlement_id"))], label="liquidación mensual")
db.rel("cper.id", "cs.period_id", ONE, MANY0, "L", "R",
       [(db.L("cper") - 50, ry("cper", "id")), (db.L("cper") - 50, ry("cs", "period_id"))])
# enlaces largos entre bloques
db.rel("emp.id_usuario", "ord.agent_id", ZONE, MANY0, "L", "L",
       [(20, ry("emp", "id_usuario")), (20, 88), (ZC - 15, 88), (ZC - 15, ry("ord", "agent_id"))], label="agente")
db.rel("tr", "oc.trainer_id", ONE, MANY0, txr(UX + 290), "R",
       [(UX + 290, yD - 45), (PX - 60, yD - 45), (PX - 60, ry("oc", "trainer_id"))], label="atribución por cupón")
xtr = db.R("trf")
db.rel("trf.activation_order_id", "ord", ZONE, ZONE, "R", (60 / wo, 1),
       [(xtr + 100, ry("trf", "activation_order_id")), (xtr + 100, yD - 20), (ZC + 60, yD - 20)], label="activa los 50.000 puntos")
db.rel("trf.id", "pl.trainer_referral_id", ONE, ZONE, "R", "R",
       [(xtr + 130, ry("trf", "id")), (xtr + 130, yD + 10), (db.R("pl") + 40, yD + 10),
        (db.R("pl") + 40, ry("pl", "trainer_referral_id"))], label="50.000 puntos para A")

# --- Leyenda y notas ---------------------------------------------------
NX = max(db.R("sp"), db.R("psm"), db.R("cat"), xsp) + 120
db.legend(NX, Y0)
db.note(NX, Y0 + 350, 620, 300,
        "<b>Origen</b><br>"
        "• Todas las tablas de radical se conservan idénticas, salvo credit_balances (saldo a favor retail) y su FK "
        "orders.balance_id: en PremiumFitness el saldo lo llevan point_lots.<br>"
        "• Externa: tb_empleados vive en la intranet; no se migra, se consulta por JOIN (orders.agent_id = id_usuario).<br><br>"
        "<b>Sin línea</b><br>"
        "• audit_logs.user_id → users.<br>"
        "• commission_settlements.trainer_id → trainers.<br>"
        "• orders_items.sap_code es una copia del código de sap_products, sin FK.<br>"
        "• orders_items guarda el descuento y la comisión vigentes de cada producto al momento de la compra: "
        "valor por defecto (variable de entorno) o estrategia del producto (product_discount_rules).<br>"
        "• Las constantes (10%, 25%, 5%, 15%, 50.000 puntos, 6 meses, 50%) son variables de entorno; "
        "cada pedido, referido y conversión copia el valor que aplicó.")
db.note(NX, Y0 + 680, 620, 330,
        "<b>Cómo se usa orders</b><br>"
        "• client_purchase: cliente asociado a un entrenador (enlace o cupón); descuento por línea (10% por defecto). "
        "Genera comisión por línea sobre la base sin IVA (15% por defecto; ej. Nutra Balance 15% de descuento y 10% de comisión) "
        "y puntos por pedido: 5% de lo pagado por los productos con IVA, sin envío, redondeado al entero (orders.reward_points). El entrenador se toma de orders_coupons.trainer_id.<br>"
        "• trainer_purchase: compra propia del entrenador, 25%; sin recompensas.<br>"
        "• redemption: canje con puntos a precio completo, sin pasarela; los puntos se descuentan en point_movements.<br>"
        "• Procesamiento: el agente del call center toma el pedido (agent_id), lo crea en SAP (sap_order_code), "
        "carga guía y transportadora y cambia status_id.<br><br>"
        "<b>Controles</b><br>"
        "• payment_reference, source_key e idempotency_key únicos: un webhook repetido no duplica recompensas.<br>"
        "• FIFO por point_lots.accrued_at; saldo canjeable = Σ remaining_points disponibles y vigentes.<br>"
        "• Referido A → B: los 50.000 puntos quedan pendientes en trainer_referrals (no caducan); la primera compra "
        "aprobada de un cliente de B crea el lote de A una sola vez.<br>"
        "• Saldo de comisión = Σ commission_movements.amount sin liquidar. Se convierte a puntos en cualquier momento "
        "(C + mín(50% × P vigentes, C)); lo no convertido se liquida en dinero al cierre de mes (commission_periods + commission_settlements). "
        "Un período cerrado no se recalcula; las correcciones entran como ajustes del mes siguiente.")

pages = [db]

if __name__ == "__main__":
    out = sys.argv[1]
    xml = '<mxfile host="app.diagrams.net" type="device">' + "".join(p.xml() for p in pages) + "</mxfile>"
    with open(out, "w", encoding="utf-8") as fh:
        fh.write(xml)
    print("ok", out)
