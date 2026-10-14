import { test, expect } from '@playwright/test';
import { sql, monitorar, conferirPagina } from './helpers';

// Mapa PMTiles/MapLibre (#36): esqueleto. Habilitar quando o SHA novo do #36 chegar.
const GOOGLE = /googleapis\.com|gstatic\.com|google\.com|fonts\.g/;

test.fixme('CT-E2E-090 Clusters em zoom baixo', async ({ page }) => {
  // Cobre CT-MAPA-008.
  // TODO: preparar a massa (massa()/sql()) e entrar como dono quando preciso (entrar()).
  // TODO: monitorar(page) e executar os passos do caso.
  // TODO: conferir o resultado esperado do catálogo e conferirPagina(page, m, 'CT-E2E-090').
});

test.fixme('CT-E2E-091 Cluster expande ao dar zoom', async ({ page }) => {
  // Cobre CT-MAPA-009.
  // TODO: preparar a massa (massa()/sql()) e entrar como dono quando preciso (entrar()).
  // TODO: monitorar(page) e executar os passos do caso.
  // TODO: conferir o resultado esperado do catálogo e conferirPagina(page, m, 'CT-E2E-091').
});

test.fixme('CT-E2E-092 Atribuição OSM visível', async ({ page }) => {
  // Cobre CT-MAPA-010.
  // TODO: preparar a massa (massa()/sql()) e entrar como dono quando preciso (entrar()).
  // TODO: monitorar(page) e executar os passos do caso.
  // TODO: conferir o resultado esperado do catálogo e conferirPagina(page, m, 'CT-E2E-092').
});

test.fixme('CT-E2E-093 Zero CSP, zero Google, zero erros de console', async ({ page }) => {
  // Cobre CT-MAPA-011/012.
  // TODO: preparar a massa (massa()/sql()) e entrar como dono quando preciso (entrar()).
  // TODO: monitorar(page) e executar os passos do caso.
  // TODO: conferir o resultado esperado do catálogo e conferirPagina(page, m, 'CT-E2E-093').
});

test.fixme('CT-E2E-094 Marcadores não usam a coordenada exata', async ({ page }) => {
  // Cobre CT-MAPA-005/007.
  // TODO: preparar a massa (massa()/sql()) e entrar como dono quando preciso (entrar()).
  // TODO: monitorar(page) e executar os passos do caso.
  // TODO: conferir o resultado esperado do catálogo e conferirPagina(page, m, 'CT-E2E-094').
});
