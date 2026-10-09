export declare function resolveModulePage<T>(
    name: string,
    appPages: Record<string, T | (() => Promise<T>)>,
    modulePages: Record<string, T | (() => Promise<T>)>,
): Promise<T>;
