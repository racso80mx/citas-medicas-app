<!-- MODAL DE AUTORREGISTRO DE MÉDICOS -->
<div class="modal-overlay" id="modalAutorregistroMedico">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-doctor" style="color: #0284c7;"></i> Autorregistro de Nuevo Médico</h3>
            <button type="button" class="close-modal" onclick="cerrarModalAutorregistroMedico()">&times;</button>
        </div>
        <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
            <input type="hidden" name="accion" value="autoregistro_medico">
            <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
            <div class="form-group" style="background: #f8f9fa; padding: 10px; border: 1px solid var(--borde-color); border-radius: 6px;">
                <label style="margin: 0; display: flex; align-items: center; gap: 8px; color: var(--imss-dorado); font-weight: bold;">
                    <input type="checkbox" name="auto_sin_cedula" id="autoSinCedulaCheck" value="1" onchange="toggleSinCedulaMedico(this)">
                    Sin Cédula Profesional (Médico Pasante / Servicio Social)
                </label>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label>Cédula Profesional <span id="labelAsteriskCedula">*</span></label>
                    <input type="text" name="auto_cedula" id="autoCedulaInput" class="form-control" required oninput="this.value = this.value.toUpperCase();">
                </div>
                <div class="form-group">
                    <label>Especialidad</label>
                    <input type="text" name="auto_especialidad" class="form-control" value="MEDICINA GENERAL" oninput="this.value = this.value.toUpperCase();">
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div class="form-group"><label>Nombre(s) *</label><input type="text" name="auto_nombre" class="form-control" required oninput="this.value = this.value.toUpperCase();"></div>
                <div class="form-group"><label>Paterno *</label><input type="text" name="auto_apaterno" class="form-control" required oninput="this.value = this.value.toUpperCase();"></div>
                <div class="form-group"><label>Materno</label><input type="text" name="auto_amaterno" class="form-control" oninput="this.value = this.value.toUpperCase();"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group"><label>Teléfono</label><input type="text" name="auto_telefono" class="form-control" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10);"></div>
                <div class="form-group"><label>Correo</label><input type="email" name="auto_correo" class="form-control" style="text-transform: none !important;"></div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalAutorregistroMedico()">Cancelar</button>
                <button type="submit" class="btn-autoregistro">Guardar Médico</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DAR DE ALTA -->
<div class="modal-overlay" id="modalAlta">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-person-walking-arrow-right" style="color: var(--imss-dorado);"></i> Confirmar Alta Médica</h3>
            <button type="button" class="close-modal" onclick="cerrarModalAlta()">&times;</button>
        </div>
        <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
            <input type="hidden" name="accion" value="dar_alta">
            <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
            <input type="hidden" name="id_censo" id="altaIdCenso" required>
            <div style="background: #fdf3f5; padding: 10px; border-left: 4px solid var(--imss-dorado); margin-bottom: 15px;">
                <strong>PACIENTE:</strong> <span id="altaNombrePaciente"></span>
            </div>
            <div class="form-group">
                <label>Médico que Autoriza *</label>
                <select name="medico_alta" id="selectMedicoAlta" class="form-control" required>
                    <option value="">-- SELECCIONAR --</option>
                    <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Motivo de Alta / Egreso *</label>
                <textarea name="motivo_alta" class="form-control" rows="3" required oninput="this.value = this.value.toUpperCase();"></textarea>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalAlta()">Cancelar</button>
                <button type="submit" class="btn-nuevo" style="background-color: var(--imss-dorado);">Confirmar Alta</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL REINGRESAR PACIENTE -->
<div class="modal-overlay modal-overlay-reingreso" id="modalReingreso">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-check" style="color: #16a34a;"></i> Reingresar Paciente</h3>
            <button type="button" class="close-modal" onclick="cerrarModalReingreso()">&times;</button>
        </div>
        <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
            <input type="hidden" name="accion" value="reingresar_paciente">
            <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
            <input type="hidden" name="id_censo" id="reingresoIdCenso" required>
            <div style="background: #f0fdf4; padding: 10px; border-left: 4px solid #16a34a; margin-bottom: 15px;">
                <strong>PACIENTE:</strong> <span id="reingresoNombrePaciente"></span>
            </div>
            <div class="form-group">
                <label>Área de Destino *</label>
                <select name="area_reingreso" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'reingresoCamaSelect')">
                    <?php foreach ($areas_hospital as $ar): ?><option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Cama Disponible</label>
                <select name="cama" id="reingresoCamaSelect" class="form-control"><option value="">-- SELECCIONE CAMA --</option></select>
            </div>
            <div class="form-group">
                <label>Médico Tratante *</label>
                <select name="medico_reingreso" id="selectMedicoReingreso" class="form-control" required>
                    <option value="">-- SELECCIONAR --</option>
                    <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Motivo de Reingreso *</label>
                <textarea name="motivo_reingreso" class="form-control" rows="3" required oninput="this.value = this.value.toUpperCase();"></textarea>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalReingreso()">Cancelar</button>
                <button type="submit" class="btn-nuevo" style="background-color: #16a34a;">Confirmar Reingreso</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL HISTORIAL DE ALTAS -->
<div class="modal-overlay" id="modalAltasHistorial">
    <div class="modal-content" style="max-width: 1300px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-clipboard-user"></i> Historial de Altas</h3>
            <button type="button" class="close-modal" onclick="cerrarModalAltas()">&times;</button>
        </div>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: flex-end;">
                <div class="form-group" style="margin:0;"><label>Filtro:</label>
                    <select id="filtroTipo" class="form-control" onchange="cambiarTipoFiltro()">
                        <option value="todos">TODOS</option><option value="hoy">HOY</option><option value="manana">MAÑANA</option>
                        <option value="semana">ESTA SEMANA</option><option value="mes">ESTE MES</option>
                        <option value="fecha_unica">FECHA ÚNICA</option><option value="rango">RANGO</option><option value="medico">POR MÉDICO</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0; display:none;" id="boxFechaUnica"><label>Fecha:</label><input type="date" id="filtroFechaUnica" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group" style="margin:0; display:none;" id="boxFechaInicio"><label>Desde:</label><input type="date" id="filtroFechaInicio" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group" style="margin:0; display:none;" id="boxFechaFin"><label>Hasta:</label><input type="date" id="filtroFechaFin" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group" style="margin:0; display:none;" id="boxMedico"><label>Médico:</label>
                    <select id="filtroMedico" class="form-control">
                        <option value="">-- SELECCIONAR --</option>
                        <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><button type="button" class="btn-nuevo" style="background: var(--imss-verde); width:100%; justify-content:center;" onclick="cargarHistorialAltas()"><i class="fa-solid fa-filter"></i> Aplicar</button></div>
            </div>
        </div>
        <div style="max-height: 450px; overflow-y: auto; border: 1px solid var(--borde-color); border-radius: 6px; background: #fff;" id="tablaAltasContainer">
            <div style="padding: 20px; text-align: center; color: #637381;">Seleccione filtros y aplique.</div>
        </div>
        <div style="margin-top: 20px; text-align: right;"><button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalAltas()">Cerrar</button></div>
    </div>
</div>

<!-- MODAL NUEVO INGRESO -->
<div class="modal-overlay" id="modalIngreso">
    <div class="modal-content" style="max-width: 850px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-user-plus"></i> Nuevo Ingreso Hospitalario</h3>
            <button type="button" class="close-modal" onclick="cerrarModalIngreso()">&times;</button>
        </div>
        <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
            <input type="hidden" name="accion" value="guardar_ingreso">
            <input type="hidden" name="id_paciente" id="inputIdPaciente" required>
            <div class="form-group">
                <label>1. Seleccionar Paciente *</label>
                <input type="text" id="inputBuscarPaciente" class="form-control" placeholder="BUSCAR POR NOMBRE O CURP..." oninput="buscarPacientesAjax(); this.value = this.value.toUpperCase();">
                <div id="resultadoBusquedaPacientes" style="max-height: 150px; overflow-y: auto; border: 1px solid var(--borde-color); display: none; background: #fff; margin-top:5px;"></div>
                <div id="pacienteSeleccionadoInfo" style="padding: 10px; background: #f0fdf4; border-radius: 6px; margin-top: 5px; display: none; color: #166534;">
                    <strong>PACIENTE:</strong> <span id="spanNombrePaciente"></span>
                </div>
            </div>
            <div id="seccionNuevoPacienteBtn" style="margin-bottom: 15px; display: none;">
                <button type="button" class="btn-accion" style="background: #0284c7; width: 100%; justify-content: center;" onclick="toggleFormularioNuevoPaciente(true)">¿Paciente nuevo? Dar de alta</button>
            </div>
            <div id="formularioNuevoPaciente" style="display: none; background: #f8f9fa; border: 1px solid var(--borde-color); border-radius: 8px; padding: 15px; margin-bottom: 15px;">
                <h4 style="margin-top: 0; color: var(--imss-verde);">Registrar Paciente Nuevo</h4>
                <div style="display: flex; justify-content: flex-end; margin-bottom: 10px;">
                    <label style="font-weight: bold; color: var(--imss-dorado); cursor: pointer;"><input type="checkbox" id="checkSinCurp" onchange="toggleSinCurp(this)"> Sin CURP</label>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group"><label>Nombre(s) *</label><input type="text" id="npNombre" class="form-control" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>Paterno *</label><input type="text" id="npApaterno" class="form-control" oninput="this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>Materno</label><input type="text" id="npAmaterno" class="form-control" oninput="this.value = this.value.toUpperCase();"></div>
                </div>
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group"><label id="labelCurp">CURP</label><input type="text" id="npCurp" class="form-control" maxlength="18" oninput="parsearCurp(this.value); this.value = this.value.toUpperCase();"></div>
                    <div class="form-group"><label>F. Nacimiento</label><input type="date" id="npFnac" class="form-control" onchange="calcularEdadPacienteNuevo()"></div>
                    <div class="form-group"><label>Edad</label><input type="text" id="npEdadCalculada" class="form-control" readonly style="background:#e9ecef;"></div>
                    <div class="form-group"><label>Sexo</label><select id="npSexo" class="form-control"><option value="MASCULINO">MASCULINO</option><option value="FEMENINO">FEMENINO</option></select></div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 10px;">
                    <button type="button" class="btn-accion" style="background: #637381;" onclick="toggleFormularioNuevoPaciente(false)">Cancelar</button>
                    <button type="button" class="btn-nuevo" style="background: #16a34a;" onclick="guardarNuevoPacienteAjax()">Guardar Paciente</button>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>2. Área de Destino *</label>
                    <select name="area_actual" id="selectAreaIngreso" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'selectCamaIngreso')">
                        <?php foreach ($areas_hospital as $ar): ?><option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>3. Cama Disponible *</label>
                    <select name="cama" id="selectCamaIngreso" class="form-control" required><option value="">-- SELECCIONE CAMA --</option></select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group"><label>Fecha Ingreso</label><input type="date" name="fecha_ingreso" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="form-group"><label>Hora Ingreso</label><input type="time" name="hora_ingreso" class="form-control" value="<?php echo date('H:i'); ?>" required></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Médico Tratante</label>
                    <select name="medico_tratante" id="selectMedicoNuevo" class="form-control" required>
                        <option value="">-- SELECCIONAR --</option>
                        <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Dieta</label><input type="text" name="dieta" class="form-control" value="GENERAL" oninput="this.value = this.value.toUpperCase();"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group"><label>Diagnóstico</label><textarea name="diagnostico_ingreso" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea></div>
                <div class="form-group"><label>Pendientes</label><textarea name="pendientes" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea></div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalIngreso()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar Ingreso</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR INGRESO -->
<div class="modal-overlay" id="modalEditar">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square"></i> Editar Ingreso</h3>
            <button type="button" class="close-modal" onclick="cerrarModalEditar()">&times;</button>
        </div>
        <form action="ingresos.php?area=<?php echo urlencode($area_seleccionada); ?>" method="POST">
            <input type="hidden" name="accion" value="editar_ingreso">
            <input type="hidden" name="area_actual_post" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
            <input type="hidden" name="id_censo" id="editIdCenso">
            <div class="form-group"><label>Paciente:</label><input type="text" id="editNombrePaciente" class="form-control" readonly style="background:#f1f3f5;"></div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Área *</label>
                    <select name="area_actual" id="editAreaActual" class="form-control" required onchange="actualizarCamasDisponiblesModal(this.value, 'editCamaSelect', document.getElementById('editIdCenso').value)">
                        <?php foreach ($areas_hospital as $ar): ?><option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Cama</label><select name="cama" id="editCamaSelect" class="form-control" required><option value="">-- SELECCIONE --</option></select></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Médico Tratante</label>
                    <select name="medico_tratante" id="selectMedicoEditar" class="form-control" required>
                        <option value="">-- SELECCIONAR --</option>
                        <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Dieta</label><input type="text" name="dieta" id="editDieta" class="form-control" oninput="this.value = this.value.toUpperCase();"></div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group"><label>Diagnóstico</label><textarea name="diagnostico_ingreso" id="editDiagnostico" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea></div>
                <div class="form-group"><label>Pendientes</label><textarea name="pendientes" id="editPendientes" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea></div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalEditar()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Actualizar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EVOLUCIÓN -->
<div class="modal-overlay" id="modalEvolucion">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fa-solid fa-stethoscope"></i> Bitácora de Evolución</h3>
            <button type="button" class="close-modal" onclick="cerrarModalEvolucion()">&times;</button>
        </div>
        <div style="margin-bottom: 20px; background: #f8f9fa; padding: 12px; border-radius: 6px; display:flex; justify-content:space-between; align-items:center;">
            <div>
                <strong>PACIENTE:</strong> <span id="evNombrePaciente" style="color: var(--imss-verde);"></span><br>
                <small>CURP: <span id="evCurpPaciente"></span> | EDAD: <span id="evEdadPaciente"></span> AÑOS</small>
            </div>
            <div><span class="badge-cama" id="evCamaPaciente"></span></div>
        </div>
        <form id="formEvolucionAjax" onsubmit="guardarEvolucionAjax(event)" style="background: #f0fdf4; padding: 20px; border-radius: 8px; border: 1px solid #bbf7d0; margin-bottom: 25px;">
            <input type="hidden" name="accion" value="guardar_evolucion_ajax">
            <input type="hidden" name="ajax" value="guardar_evolucion_ajax">
            <input type="hidden" name="area_actual" value="<?php echo htmlspecialchars($area_seleccionada); ?>">
            <input type="hidden" name="id_censo_ev" id="evIdCenso">
            <h4 style="margin-top:0; color: #166534;"><i class="fa-solid fa-plus-circle"></i> Nuevo Registro Clínico</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                <div class="form-group"><label>Fecha:</label><input type="date" name="fecha_evento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="form-group"><label>Hora:</label><input type="time" name="hora_evento" class="form-control" value="<?php echo date('H:i'); ?>" required></div>
                <div class="form-group"><label>Tipo:</label>
                    <select name="tipo_evento" class="form-control" required>
                        <option value="EVOLUCIÓN MÉDICA">EVOLUCIÓN MÉDICA</option><option value="TRASLADO DE ÁREA">TRASLADO DE ÁREA</option>
                        <option value="NOTA DE ENFERMERÍA">NOTA DE ENFERMERÍA</option><option value="INTERCONSULTA">INTERCONSULTA</option>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="form-group"><label>Destino (Área):</label>
                    <select name="area_evento" class="form-control" required>
                        <?php foreach ($areas_hospital_todas as $ar): ?><option value="<?php echo htmlspecialchars($ar); ?>"><?php echo htmlspecialchars($ar); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Médico Tratante:</label>
                    <select name="medico_evento" id="selectMedicoEvolucion" class="form-control" required>
                        <option value="">-- SELECCIONAR --</option>
                        <?php foreach ($cat_medicos as $med): ?><option value="<?php echo htmlspecialchars($med); ?>"><?php echo htmlspecialchars($med); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Descripción / Notas Clínicas:</label><textarea name="descripcion_evento" class="form-control" rows="2" required oninput="this.value = this.value.toUpperCase();"></textarea></div>
            <div class="section-box">
                <h5>Tratamiento y Estudios</h5>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 12px;">
                    <div class="form-group" style="margin:0;"><label>Tratamiento:</label><textarea name="tratamiento_farmacologico" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea></div>
                    <div class="form-group" style="margin:0;"><label>Indicaciones:</label><textarea name="indicaciones_generales" class="form-control" rows="3" oninput="this.value = this.value.toUpperCase();"></textarea></div>
                </div>
                <div class="form-group"><label>Estudios (Laboratorio / Imagen):</label>
                    <select id="selectEstudiosLabMulti" class="form-control" multiple style="width: 100%;">
                        <?php foreach ($cat_estudios_lab as $est): ?><option value="<?php echo htmlspecialchars($est['estudio']); ?>"><?php echo htmlspecialchars($est['estudio']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><textarea name="estudios_solicitados" id="textareaEstudiosSolicitados" class="form-control" rows="2" oninput="this.value = this.value.toUpperCase();"></textarea></div>
            </div>
            <div style="text-align: right;"><button type="submit" class="btn-nuevo" style="background-color: #0d9488;">Guardar Registro</button></div>
        </form>
        <h4 style="margin-bottom: 10px;"><i class="fa-solid fa-timeline"></i> Historial</h4>
        <div style="max-height: 300px; overflow-y: auto; border: 1px solid var(--borde-color); background: #fff;" id="contenedorBitacoraEventos">
            <div style="padding: 15px; text-align: center;">Cargando...</div>
        </div>
        <div style="margin-top: 20px; text-align: right;"><button type="button" class="btn-accion" style="background:#637381;" onclick="cerrarModalEvolucion()">Cerrar</button></div>
    </div>
</div>