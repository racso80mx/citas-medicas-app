CREATE TABLE IF NOT EXISTS notas_psicologia (
    id_nota SERIAL PRIMARY KEY,
    id_cita INT,
    no_expediente VARCHAR(50),
    fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    motivo_consulta TEXT,
    observaciones TEXT,
    estrategias TEXT,
    seguimiento_tx TEXT,
    impresion_dx VARCHAR(255),
    impresion_diagnostica_dsm TEXT,
    plan_diagnostico TEXT,
    pronostico TEXT,
    proxima_cita VARCHAR(100),
    usuario_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);