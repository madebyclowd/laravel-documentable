declare module 'node:crypto' {
  export interface Hash {
    update(data: string | Uint8Array | ArrayBuffer | ArrayBufferView): this;
    digest(encoding?: string): string;
  }
  export function createHash(algorithm: string): Hash;
  export function randomBytes(size: number): Uint8Array & { toString(encoding?: string): string };
}

declare module 'node:fs' {
  export function readFileSync(path: string, encoding: string): string;
}

declare module 'node:path' {
  export function dirname(path: string): string;
  export function resolve(...paths: string[]): string;
  export function join(...paths: string[]): string;
}

declare module 'node:url' {
  export function fileURLToPath(url: string | URL): string;
}

declare const Buffer: {
  from(data: Uint8Array | ArrayBuffer | string): { toString(encoding?: string): string };
  concat(list: Uint8Array[]): Uint8Array<ArrayBuffer>;
};
