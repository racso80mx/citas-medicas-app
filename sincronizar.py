import os
os.environ["PLAYWRIGHT_BROWSERS_PATH"] = "/var/www/html/playwright_browsers"

import asyncio
from playwright.async_api import async_playwright
import sys
import json
import re
import warnings

# Ocultar cualquier advertencia de Python para no corromper el JSON de salida
warnings.filterwarnings("ignore")

SIHBI_USER = 'YGISEA'
SIHBI_PASS = 'Cambiame82$'
SIHBI_CLUES = 'TCIMB001870'
BASE_URL = 'http://10.127.42.9'

async def extraer_detalles_completos(context, url):
    datos = {
        "nombre": "", "apaterno": "", "amaterno": "", "sexo": "", 
        "fnacimiento": "", "tipo_sanguineo": "", "rfc": "", "telefono": "", 
        "correo": "", "curp": "", "estado": "", "municipio": "", 
        "localidad": "", "tipo_asentamiento": "", "calle": "", 
        "num_interior": "", "num_exterior": "", "domicilio": ""
    }
    
    if not url:
        return datos

    page = await context.new_page()
    try:
        await page.goto(url, timeout=35000, wait_until="domcontentloaded")
        await asyncio.sleep(2)
        
        for tab_texto in ["Datos Personales", "Domicilio"]:
            try:
                tab = page.locator(f"text='{tab_texto}'")
                if await tab.count() > 0:
                    await tab.first.click()
                    await asyncio.sleep(1)
            except:
                pass

        texto_pagina = await page.inner_text("body")

        def buscar_regex(palabras_clave):
            for clave in palabras_clave:
                patron = r"(?:" + clave + r")\s*[:\-]?\s*([^\n\r]+)"
                match = re.search(patron, texto_pagina, re.IGNORECASE)
                if match:
                    val = match.group(1).strip()
                    if val and val not in ["---", "0000000000", "Seleccione...", "", "N/D"]:
                        return val
            return ""

        datos["curp"] = buscar_regex([r"CURP", r"C\.U\.R\.P"])
        datos["apaterno"] = buscar_regex([r"Primer Apellido", r"Apellido Paterno", r"1er Apellido"])
        datos["amaterno"] = buscar_regex([r"Segundo Apellido", r"Apellido Materno", r"2do Apellido"])
        datos["nombre"] = buscar_regex([r"Nombre\(s\)", r"Nombre"])
        datos["sexo"] = buscar_regex([r"Sexo", r"Género"])
        datos["fnacimiento"] = buscar_regex([r"Fecha de Nacimiento", r"Nacimiento"])
        datos["tipo_sanguineo"] = buscar_regex([r"Tipo Sanguíneo", r"Grupo Sanguíneo"])
        datos["rfc"] = buscar_regex([r"RFC"])
        datos["telefono"] = buscar_regex([r"Teléfono", r"Celular", r"Tel"])
        datos["correo"] = buscar_regex([r"Correo Electrónico", r"Correo", r"Email"])
        
        datos["estado"] = buscar_regex([r"Entidad Federativa", r"Estado"])
        datos["municipio"] = buscar_regex([r"Municipio", r"Delegación"])
        datos["localidad"] = buscar_regex([r"Localidad", r"Ciudad"])
        datos["tipo_asentamiento"] = buscar_regex([r"Tipo Asentamiento", r"Asentamiento", r"Colonia"])
        datos["calle"] = buscar_regex([r"Calle", r"Vialidad"])
        datos["num_exterior"] = buscar_regex([r"Núm\. Exterior", r"Num\. Exterior", r"No\. Exterior", r"Ext"])
        datos["num_interior"] = buscar_regex([r"Núm\. Interior", r"Num\. Interior", r"No\. Interior", r"Int"])
        cp = buscar_regex([r"Código Postal", r"C\.P\.", r"CP"])

        partes = []
        if datos["calle"]: partes.append(f"CALLE {datos['calle']}")
        if datos["num_exterior"]: partes.append(f"EXT. {datos['num_exterior']}")
        if datos["num_interior"]: partes.append(f"INT. {datos['num_interior']}")
        if datos["tipo_asentamiento"]: partes.append(datos["tipo_asentamiento"])
        if datos["localidad"]: partes.append(datos["localidad"])
        if datos["municipio"]: partes.append(datos["municipio"])
        if datos["estado"]: partes.append(datos["estado"])
        if cp: partes.append(f"C.P. {cp}")
        datos["domicilio"] = ", ".join(partes)

    except Exception as e:
        pass
    finally:
        await page.close()
        
    return datos

async def extraer_pacientes_sihbi():
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--single-process']
        )
        context = await browser.new_context()
        page = await context.new_page()

        try:
            await page.goto(f"{BASE_URL}/Identity/Account/Login", timeout=45000, wait_until="domcontentloaded")
            await asyncio.sleep(2)

            try: await page.fill("input[name='Input.Username']", SIHBI_USER)
            except: await page.fill("input[type='text']", SIHBI_USER)

            try: await page.fill("input[name='Input.Password']", SIHBI_PASS)
            except: await page.fill("input[type='password']", SIHBI_PASS)

            try: await page.fill("input[name='Input.Clues']", SIHBI_CLUES)
            except: pass

            await asyncio.sleep(2)
            btn_ingresar = page.locator("button:has-text('INGRESAR'), input[type='submit'], button[type='submit']")
            await btn_ingresar.first.click()

            for _ in range(15):
                await asyncio.sleep(1)
                if "Login" not in page.url: break

            if "Login" in page.url:
                return [{"expediente": "ERROR", "nombre": "Fallo de credenciales o sesión bloqueada en intranet"}]

            args = sys.argv[1:]
            if "--url" in args:
                idx_url = args.index("--url") + 1
                if idx_url < len(args):
                    target_url = args[idx_url]
                    detalles = await extraer_detalles_completos(context, target_url)
                    return [detalles]

            await page.goto(f"{BASE_URL}/SeguimientoPacientes", timeout=45000, wait_until="domcontentloaded")
            await asyncio.sleep(4)

            filas = []
            for selector in ["table tr", "tbody tr", ".dxgvDataRow", ".k-grid-content tr", "tr[role='row']"]:
                filas = await page.locator(selector).all()
                if len(filas) > 1:
                    break

            pacientes = []
            for fila in filas:
                try:
                    html_fila = await fila.inner_html()
                    columnas = await fila.locator("td").all_inner_texts()
                    columnas = [c.strip() for c in columnas if c.strip() != ""]
                    
                    if len(columnas) >= 3:
                        exp_idx = -1
                        for idx, col in enumerate(columnas):
                            if col.startswith("T-") or col.startswith("SE-") or (len(col) > 5 and "-" in col):
                                exp_idx = idx
                                break
                        
                        if exp_idx == -1: continue

                        exp = columnas[exp_idx]
                        nombre_tabla = columnas[exp_idx + 1] if len(columnas) > exp_idx + 1 else "SIN NOMBRE"
                        sexo = columnas[exp_idx + 2] if len(columnas) > exp_idx + 2 else "N/D"
                        fecha = columnas[exp_idx + 3] if len(columnas) > exp_idx + 3 else "N/D"
                        medico = columnas[exp_idx + 4] if len(columnas) > exp_idx + 4 else "N/D"
                        motivo = columnas[exp_idx + 5] if len(columnas) > exp_idx + 5 else "Consulta general"

                        url_exp = ""
                        try:
                            link_loc = fila.locator("a[href*='Detalle'], a[href*='pacienteId'], a[href*='personaId']")
                            if await link_loc.count() > 0:
                                href = await link_loc.first.get_attribute("href")
                                if href:
                                    url_exp = href if href.startswith("http") else BASE_URL + (href if href.startswith("/") else "/" + href)
                        except:
                            pass

                        if not url_exp:
                            mp = re.search(r'personaId=([0-9]+)', html_fila)
                            mac = re.search(r'pacienteId=([0-9]+)', html_fila)
                            if mp and mac:
                                url_exp = f"{BASE_URL}/ExpedientePacientes/Detalle/?returnURL=/SeguimientoPacientes&personaId={mp.group(1)}&pacienteId={mac.group(1)}"

                        pacientes.append({
                            "expediente": exp, 
                            "nombre": nombre_tabla.replace("\n", " "), 
                            "sexo": sexo,
                            "fecha_llegada": fecha.replace("\n", " "), 
                            "medico": medico.replace("\n", " "),
                            "motivo": motivo.replace("\n", " "),
                            "url_expediente": url_exp,
                            "curp": "", "telefono": "", "domicilio": ""
                        })
                except:
                    continue

            return pacientes if pacientes else [{"expediente": "AVISO", "nombre": "No se encontraron registros activos en la intranet"}]

        except Exception as e:
            return [{"expediente": "EXCEPCION", "nombre": str(e)[:150]}]
        finally:
            await browser.close()

if __name__ == "__main__":
    try:
        resultado = asyncio.run(extraer_pacientes_sihbi())
        print(json.dumps(resultado))
    except Exception as e:
        print(json.dumps([{"expediente": "FATAL", "nombre": str(e)[:150]}]))