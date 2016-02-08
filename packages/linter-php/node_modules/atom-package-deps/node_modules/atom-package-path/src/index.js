'use strict'

const callsite = require('sb-callsite')
const regexes = [
  /[\\\/]packages[\\\/]([^\\\/]+)[\\\/]/,
  /[\\\/]([\w-_]+)[\\\/](?:lib|src)[\\\/]/,
  /[\\\/]([\w-_]+)[\\\/][\w-_]+\..+$/
]

export function guessFromFilePath(filePath) {
  let matches
  matches = regexes[0].exec(filePath)
  if (matches) {
    return matches[1]
  }
  matches = regexes[1].exec(filePath)
  if (matches) {
    return matches[1]
  }
  matches = regexes[2].exec(filePath)
  if (matches) {
    return matches[1]
  }
  return null
}

export function guess() {
  const filePath = callsite.capture()[2].file
  return guessFromFilePath(filePath)
}
