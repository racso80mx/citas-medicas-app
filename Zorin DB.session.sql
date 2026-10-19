CREATE TABLE nota_medica (
    id_nota SERIAL PRIMARY KEY,
    id_paciente VARCHAR(50) NOT NULL,
    id_unidad_medica VARCHAR(50) NOT NULL,
    fecha_hora_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Signos Vitales y Antropometría
    tension_arterial VARCHAR(10) NOT NULL,
    frecuencia_cardiaca INT NOT NULL,
    frecuencia_respiratoria INT NOT NULL,
    temperatura DECIMAL(4,2) NOT NULL,
    saturacion_oxigeno INT NULL,
    peso DECIMAL(5,2) NOT NULL,
    talla DECIMAL(5,2) NOT NULL,
    imc DECIMAL(4,2) NULL,
    
    -- Padecimiento y Exploración
    motivo_consulta TEXT NOT NULL,
    padecimiento_actual TEXT NOT NULL,
    exploracion_fisica TEXT NOT NULL,
    estudios_previos TEXT NULL,
    
    -- Diagnóstico y Tratamiento
    cie10_principal VARCHAR(10) NOT NULL,
    diagnostico_principal_desc TEXT NOT NULL,
    cie10_secundarios TEXT NULL,
    plan_tratamiento TEXT NOT NULL,
    receta_medicamentos JSON NULL,
    pronostico VARCHAR(50) NOT NULL,
    referencia_medica TEXT NULL,
    
    -- Validación y Cierre
    nombre_medico VARCHAR(150) NOT NULL,
    cedula_profesional VARCHAR(20) NOT NULL,
    firma_digital TEXT NOT NULL
);