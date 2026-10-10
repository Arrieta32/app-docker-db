DROP TABLE IF EXISTS "CitaMedica" CASCADE;
DROP TABLE IF EXISTS "HorarioAtencion" CASCADE;
DROP TABLE IF EXISTS "MedicoEspecialidad" CASCADE;
DROP TABLE IF EXISTS "Especialidad" CASCADE;
DROP TABLE IF EXISTS "Medico" CASCADE;
DROP TABLE IF EXISTS "Paciente" CASCADE;
DROP TABLE IF EXISTS "Persona" CASCADE;
DROP TABLE IF EXISTS "SeguroMedico" CASCADE;
DROP TABLE IF EXISTS "DetalleCatalogo" CASCADE;
DROP TABLE IF EXISTS "Catalogo" CASCADE;
DROP TABLE IF EXISTS "Empresa" CASCADE;

-- ============================================================================
-- 1. NÚCLEO MULTIEMPRESA
-- ============================================================================
CREATE TABLE "Empresa" (
    "IdEmpresa"       SERIAL PRIMARY KEY,
    "RUC"             VARCHAR(20) NOT NULL UNIQUE,
    "RazonSocial"     VARCHAR(120) NOT NULL,
    "NombreFantasia"  VARCHAR(120) NOT NULL,
    "Activo"          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ============================================================================
-- 2. CATÁLOGOS DINÁMICOS (MAESTRO - DETALLE)
-- ============================================================================
CREATE TABLE "Catalogo" (
    "CodigoCatalogo" VARCHAR(30) PRIMARY KEY,
    "Nombre"         VARCHAR(80) NOT NULL,
    "Descripcion"    TEXT,
    "Activo"         BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE "DetalleCatalogo" (
    "CodigoCatalogo" VARCHAR(30) NOT NULL REFERENCES "Catalogo"("CodigoCatalogo") ON DELETE CASCADE,
    "CodigoItem"     VARCHAR(20) NOT NULL,
    "Descripcion"    VARCHAR(100) NOT NULL,
    "Orden"          INT DEFAULT 0,
    "Activo"         BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY ("CodigoCatalogo", "CodigoItem")
);

-- ============================================================================
-- 3. SUPERCLASE PERSONA (DATOS COMUNES)
-- ============================================================================
CREATE TABLE "Persona" (
    "IdPersona"       SERIAL PRIMARY KEY,
    "IdEmpresa"       INT NOT NULL REFERENCES "Empresa"("IdEmpresa"),
    "NumeroDocumento" VARCHAR(30) NOT NULL,
    "NombreCompleto"  VARCHAR(100) NOT NULL,
    "FechaNacimiento" DATE NOT NULL,
    "Telefono"        VARCHAR(30),
    
    -- Catálogos dinámicos
    "CatTipoDoc"      VARCHAR(30) NOT NULL DEFAULT 'TIPO_DOC',
    "ItemTipoDoc"     VARCHAR(20) NOT NULL,
    
    "CatSexo"         VARCHAR(30) NOT NULL DEFAULT 'SEXO',
    "ItemSexo"        VARCHAR(20) NOT NULL,
    
    "CatEstadoCivil"  VARCHAR(30) NOT NULL DEFAULT 'ESTADO_CIVIL',
    "ItemEstadoCivil" VARCHAR(20),
    
    "CatCiudad"       VARCHAR(30) NOT NULL DEFAULT 'CIUDAD',
    "ItemCiudad"      VARCHAR(20) NOT NULL,
    
    "Activo"          BOOLEAN NOT NULL DEFAULT TRUE,

    -- Un documento no se repite por empresa y tipo
    CONSTRAINT unq_persona_doc_empresa UNIQUE ("IdEmpresa", "CatTipoDoc", "ItemTipoDoc", "NumeroDocumento"),
    
    CONSTRAINT fk_persona_tipodoc FOREIGN KEY ("CatTipoDoc", "ItemTipoDoc") 
        REFERENCES "DetalleCatalogo"("CodigoCatalogo", "CodigoItem"),
    CONSTRAINT fk_persona_sexo FOREIGN KEY ("CatSexo", "ItemSexo") 
        REFERENCES "DetalleCatalogo"("CodigoCatalogo", "CodigoItem"),
    CONSTRAINT fk_persona_estadocivil FOREIGN KEY ("CatEstadoCivil", "ItemEstadoCivil") 
        REFERENCES "DetalleCatalogo"("CodigoCatalogo", "CodigoItem"),
    CONSTRAINT fk_persona_ciudad FOREIGN KEY ("CatCiudad", "ItemCiudad") 
        REFERENCES "DetalleCatalogo"("CodigoCatalogo", "CodigoItem")
);

-- ============================================================================
-- 4. SUBCLASES IS-A (PACIENTE Y MEDICO)
-- ============================================================================
CREATE TABLE "SeguroMedico" (
    "IdSeguro"            SERIAL PRIMARY KEY,
    "IdEmpresa"           INT NOT NULL REFERENCES "Empresa"("IdEmpresa"),
    "Nombre"              VARCHAR(80) NOT NULL,
    "PorcentajeCobertura" NUMERIC(5, 2) NOT NULL CHECK ("PorcentajeCobertura" BETWEEN 0 AND 100),
    "Activo"              BOOLEAN NOT NULL DEFAULT TRUE
);

-- Subclase Paciente (PK es FK a Persona)
CREATE TABLE "Paciente" (
    "IdPersona" INT PRIMARY KEY REFERENCES "Persona"("IdPersona") ON DELETE CASCADE,
    "IdSeguro"  INT REFERENCES "SeguroMedico"("IdSeguro"),
    "Activo"    BOOLEAN NOT NULL DEFAULT TRUE
);

-- Subclase Medico (PK es FK a Persona)
CREATE TABLE "Medico" (
    "IdPersona"           INT PRIMARY KEY REFERENCES "Persona"("IdPersona") ON DELETE CASCADE,
    "RegistroProfesional" VARCHAR(30) NOT NULL UNIQUE,
    "Activo"              BOOLEAN NOT NULL DEFAULT TRUE
);

-- ============================================================================
-- 5. ESPECIALIDADES Y ASIGNACIONES
-- ============================================================================
CREATE TABLE "Especialidad" (
    "IdEspecialidad" SERIAL PRIMARY KEY,
    "IdEmpresa"      INT NOT NULL REFERENCES "Empresa"("IdEmpresa"),
    "Nombre"         VARCHAR(60) NOT NULL,
    "ArancelBase"    NUMERIC(10, 2) NOT NULL CHECK ("ArancelBase" > 0),
    "Activo"         BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT unq_especialidad_empresa UNIQUE ("IdEmpresa", "Nombre")
);

CREATE TABLE "MedicoEspecialidad" (
    "IdMedico"       INT NOT NULL REFERENCES "Medico"("IdPersona"),
    "IdEspecialidad" INT NOT NULL REFERENCES "Especialidad"("IdEspecialidad"),
    "Activo"         BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY ("IdMedico", "IdEspecialidad")
);

-- ============================================================================
-- 6. HORARIO DE ATENCIÓN Y CITAS MÉDICAS
-- ============================================================================
CREATE TABLE "HorarioAtencion" (
    "IdHorario"          SERIAL PRIMARY KEY,
    "IdEmpresa"          INT NOT NULL REFERENCES "Empresa"("IdEmpresa"),
    "IdMedico"           INT NOT NULL REFERENCES "Medico"("IdPersona"),
    "IdEspecialidad"     INT NOT NULL REFERENCES "Especialidad"("IdEspecialidad"),
    "DiaSemana"          SMALLINT NOT NULL CHECK ("DiaSemana" BETWEEN 1 AND 7), -- 1: Lunes .. 7: Domingo
    "HoraInicio"         TIME NOT NULL,
    "HoraFin"            TIME NOT NULL,
    "DuracionMinutos"    INT NOT NULL DEFAULT 20 CHECK ("DuracionMinutos" > 0),
    "Consultorio"        VARCHAR(20) NOT NULL,
    "Activo"             BOOLEAN NOT NULL DEFAULT TRUE,
    CONSTRAINT chk_rango_horario CHECK ("HoraFin" > "HoraInicio")
);

CREATE TABLE "CitaMedica" (
    "IdCita"         SERIAL PRIMARY KEY,
    "IdEmpresa"      INT NOT NULL REFERENCES "Empresa"("IdEmpresa"),
    "IdPaciente"     INT NOT NULL REFERENCES "Paciente"("IdPersona"),
    "IdMedico"       INT NOT NULL REFERENCES "Medico"("IdPersona"),
    "IdEspecialidad" INT NOT NULL REFERENCES "Especialidad"("IdEspecialidad"),
    "FechaHora"      TIMESTAMP NOT NULL,
    "Consultorio"    VARCHAR(20) NOT NULL,
    "ArancelCobrado" NUMERIC(10, 2) NOT NULL,
    
    "CatEstadoCita"  VARCHAR(30) NOT NULL DEFAULT 'ESTADO_CITA',
    "ItemEstadoCita" VARCHAR(20) NOT NULL DEFAULT 'AGENDADA',
    "Activo"         BOOLEAN NOT NULL DEFAULT TRUE,
    
    CONSTRAINT unq_medico_cita UNIQUE ("IdEmpresa", "IdMedico", "FechaHora"),
    CONSTRAINT fk_cita_estado FOREIGN KEY ("CatEstadoCita", "ItemEstadoCita") 
        REFERENCES "DetalleCatalogo"("CodigoCatalogo", "CodigoItem")
);