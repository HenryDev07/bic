-- 0013: asistente de IA. Conversaciones por usuario; mensajes inmutables (auditoría). Las herramientas son de solo lectura.
CREATE TABLE IF NOT EXISTS ai_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_conv_user (user_id, id),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- role: user | assistant | tool (llamada a herramienta: nombre y argumentos, nunca los resultados).
CREATE TABLE IF NOT EXISTS ai_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('user','assistant','tool') NOT NULL,
  content TEXT NOT NULL,
  provider VARCHAR(30) NULL,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ai_msg_conv (conversation_id, id),
  KEY idx_ai_msg_user_day (user_id, role, created_at),
  FOREIGN KEY (business_id) REFERENCES businesses (id),
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations (id),
  FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
