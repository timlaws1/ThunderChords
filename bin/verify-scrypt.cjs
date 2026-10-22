#!/usr/bin/env node
'use strict'

const { scrypt, timingSafeEqual } = require('node:crypto')
const { promisify } = require('node:util')

const scryptAsync = promisify(scrypt)
const SCRYPT = { N: 16384, r: 8, p: 1, maxmem: 64 * 1024 * 1024 }

async function verify(password, stored) {
  const [algo, saltB64, hashB64] = String(stored || '').split('$')
  if (algo !== 'scrypt' || !saltB64 || !hashB64) return false
  const salt = Buffer.from(saltB64, 'base64url')
  const expected = Buffer.from(hashB64, 'base64url')
  const actual = await scryptAsync(String(password), salt, expected.length, SCRYPT)
  return timingSafeEqual(expected, Buffer.from(actual))
}

const password = process.argv[2]
const stored = process.argv[3]

verify(password, stored)
  .then(ok => process.exit(ok ? 0 : 1))
  .catch(() => process.exit(1))
