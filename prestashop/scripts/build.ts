import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from "node:fs";
import { basename, join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const MODULE_SLUG = "zenocpg";

const ROOT_DIR = resolve(__dirname, "..");
const SOURCE_DIR = join(ROOT_DIR, MODULE_SLUG);
const DIST_DIR = join(ROOT_DIR, "dist");
const TEMP_DIR = join(ROOT_DIR, ".tmp-build");
const STAGE_DIR = join(TEMP_DIR, MODULE_SLUG);

const IGNORED_NAMES = new Set([
  ".DS_Store",
  "__MACOSX",
  "Thumbs.db",
]);

function shouldIgnorePath(targetPath: string): boolean {
  const name = basename(targetPath);
  return name.startsWith(".") || IGNORED_NAMES.has(name);
}

function copyFiltered(source: string, destination: string): void {
  if (shouldIgnorePath(source)) {
    return;
  }

  const entries = readdirSync(source, { withFileTypes: true });
  mkdirSync(destination, { recursive: true });

  for (const entry of entries) {
    const sourcePath = join(source, entry.name);
    const destinationPath = join(destination, entry.name);

    if (shouldIgnorePath(sourcePath)) {
      continue;
    }

    if (entry.isDirectory()) {
      copyFiltered(sourcePath, destinationPath);
      continue;
    }

    if (entry.isFile()) {
      cpSync(sourcePath, destinationPath);
    }
  }
}

function run(): void {
  if (!existsSync(SOURCE_DIR)) {
    throw new Error(`${MODULE_SLUG} directory not found`);
  }

  const zipName = `${MODULE_SLUG}.zip`;
  const zipPath = join(DIST_DIR, zipName);

  rmSync(TEMP_DIR, { recursive: true, force: true });
  rmSync(DIST_DIR, { recursive: true, force: true });
  mkdirSync(DIST_DIR, { recursive: true });
  mkdirSync(STAGE_DIR, { recursive: true });

  copyFiltered(SOURCE_DIR, STAGE_DIR);

  const zipResult = spawnSync("zip", ["-r", zipPath, MODULE_SLUG], {
    cwd: TEMP_DIR,
    stdio: "inherit",
  });

  if (zipResult.status !== 0) {
    throw new Error("Failed to generate zip");
  }

  rmSync(TEMP_DIR, { recursive: true, force: true });
  console.log(`Zip created: ${zipPath}`);
}

run();
